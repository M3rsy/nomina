#!/usr/bin/env bash

# Limpia datos sensibles para presentación:
# - Base de datos (empleados, nómina, auditoría, etc.)
# - Archivos de carga/subidas
# - Backups guardados en storage
# - Logs y caché de Laravel

set -euo pipefail
shopt -s nullglob

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$APP_DIR"

if [[ "${1:-}" == "--auto" || "${1:-}" == "--force" ]]; then
    FORCE=1
else
    FORCE=0
fi

if [[ -f .env ]]; then
    # shellcheck disable=SC1090
    source .env
else
    echo "[ERROR] No se encontró .env en $APP_DIR"
    exit 1
fi

if [[ "${APP_ENV:-}" == "production" && "$FORCE" -ne 1 ]]; then
    echo "[ERROR] APP_ENV=production detectado. Si querés correrlo igual, ejecutá:"
    echo "  ./scripts/limpiar-presentacion.sh --force"
    exit 1
fi

if [[ "$FORCE" -ne 1 ]]; then
    echo "⚠️  ADVERTENCIA: Esto borra datos de empleado/nómina/auditoría/archivos y respaldos (modo demo-segura)."
    read -rp "Escribí 'LIMPIAR' para continuar (o enter para cancelar): " ok
    [[ "$ok" == "LIMPIAR" ]] || { echo "Cancelado."; exit 1; }
fi

# ------------------------------------------------------------
# 1) Limpieza de base de datos (solo se afecta demo-segura)
# ------------------------------------------------------------

if command -v docker >/dev/null 2>&1 && [ -f docker-compose.yml ] && docker compose ps -q db >/dev/null 2>&1; then
    if [[ -n "$(docker compose ps -q db)" ]]; then
        PSQL_CMD="docker compose exec -T db psql -U ${DB_USERNAME} -d ${DB_DATABASE}"
    else
        PSQL_CMD="psql -h ${DB_HOST:-127.0.0.1} -p ${DB_PORT:-5432} -U ${DB_USERNAME} -d ${DB_DATABASE}"
    fi
else
    PSQL_CMD="psql -h ${DB_HOST:-127.0.0.1} -p ${DB_PORT:-5432} -U ${DB_USERNAME} -d ${DB_DATABASE}"
fi

# Definí solo las tablas que querés tocar en demo
TARGET_TABLE_PATTERNS=(
    "employee%"
    "empleado%"
    "payroll%"
    "nomina%"
    "nomina_%"
    "attendance%"
    "audit%"
    "upload%"
    "uploaded%"
    "file%"
    "archiv%"
    "archivo%"
)

TARGET_TABLE_WHERE=""
for pattern in "${TARGET_TABLE_PATTERNS[@]}"; do
    clause="table_name LIKE '${pattern}'"
    if [[ -z "$TARGET_TABLE_WHERE" ]]; then
        TARGET_TABLE_WHERE="$clause"
    else
        TARGET_TABLE_WHERE+=" OR $clause"
    fi
done

echo "Limpiando base de datos (${DB_DATABASE}) con filtro de demo segura..."

cat <<SQL | $PSQL_CMD -v ON_ERROR_STOP=1
DO \$\$
DECLARE
    tables text;
BEGIN
    SELECT string_agg(format('%I', table_name), ', ')
      INTO tables
    FROM information_schema.tables
    WHERE table_schema = 'public'
      AND table_type = 'BASE TABLE'
      AND table_name <> 'migrations'
      AND (${TARGET_TABLE_WHERE});

    IF tables IS NULL THEN
        RAISE NOTICE 'No hay tablas de demo-segura para truncar con el filtro actual.';
        RETURN;
    END IF;

    EXECUTE format('TRUNCATE TABLE %s RESTART IDENTITY CASCADE', tables);
    RAISE NOTICE 'Truncadas tablas: %', tables;
END \$\$;
SQL

# ------------------------------------------------------------
# 2) Limpieza de archivos para demo
# ------------------------------------------------------------

echo "Limpiando archivos de storage para presentación..."

TARGET_DIRS=(
    "storage/app/public"
    "storage/app/private"
    "storage/app/backup-temp"
    "storage/app/nomina-backups"
    "storage/logs"
    "storage/framework/cache/data"
    "storage/framework/sessions"
    "storage/framework/views"
)

for dir in "${TARGET_DIRS[@]}"; do
    if [[ -d "$dir" ]]; then
        find "$dir" -mindepth 1 -delete
    fi
done

echo "Limpiando respaldos (archivos .sql/.dump/.backup/.gz en root del proyecto)..."
find . -maxdepth 2 -type f \( -name "*.sql" -o -name "*.sql.gz" -o -name "*.dump" -o -name "*.backup" -o -name "*.bak" \) -delete

echo "Limpieza finalizada ✅"
echo "Si te deja la app sin usuario admin, podés crear uno nuevo con:"
echo "docker compose exec app php artisan make:filament-user"
echo "(o tu flujo normal de alta de usuario)"
