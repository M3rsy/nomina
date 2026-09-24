#!/bin/sh

# Limpia datos sensibles para presentación preservando empleados:
# - Conserva empleados, revisiones de empleados y configuración laboral base.
# - Borra datos operativos de nómina, asistencia, auditoría, cargas y sesiones.
# - Limpia archivos de carga/subidas, respaldos, logs y caché de Laravel.

set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
APP_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)"
cd "$APP_DIR"

FORCE=0
DRY_RUN=0

for arg in "$@"; do
    case "$arg" in
        --auto|--force)
            FORCE=1
            ;;
        --dry-run)
            DRY_RUN=1
            ;;
        *)
            echo "[ERROR] Opción no soportada: $arg" >&2
            echo "Uso: ./scripts/limpiar-presentacion.sh [--dry-run] [--force]" >&2
            exit 1
            ;;
    esac
done

if [ -f .env ]; then
    # shellcheck disable=SC1091
    . ./.env
else
    echo "[ERROR] No se encontró .env en $APP_DIR" >&2
    exit 1
fi

if [ "${APP_ENV:-}" = "production" ] && [ "$FORCE" -ne 1 ]; then
    echo "[ERROR] APP_ENV=production detectado. Si querés correrlo igual, ejecutá:" >&2
    echo "  ./scripts/limpiar-presentacion.sh --force" >&2
    exit 1
fi

if [ "$FORCE" -ne 1 ] && [ "$DRY_RUN" -ne 1 ]; then
    echo "⚠️  ADVERTENCIA: Esto borra datos operativos de nómina/asistencia/auditoría/archivos y respaldos."
    echo "    Se preservan empleados, revisiones de empleados y configuración laboral base."
    printf "Escribí 'LIMPIAR' para continuar (o enter para cancelar): "
    read -r ok
    [ "$ok" = "LIMPIAR" ] || { echo "Cancelado."; exit 1; }
fi

PRESERVED_TABLES="
employees
employee_revisions
employee_schedule_assignments
employee_position_assignments
work_schedules
work_schedule_profiles
work_schedule_profile_publications
holidays
companies
users
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions
"

CLEANUP_TABLES="
payroll_run_telemetry
payroll_runs
payroll_review_entries
payroll_results
attendance_variation_acknowledgements
attendance_fact_generations
attendance_exceptions
overtime_decision_batches
overtime_decisions
justified_absences
raw_marks
uploaded_files
audit_entries
login_attempts
jobs
job_batches
failed_jobs
cache
cache_locks
sessions
"

TARGET_DIRS="
storage/app/public
storage/app/private
storage/app/backup-temp
storage/app/nomina-backups
storage/logs
storage/framework/cache/data
storage/framework/sessions
storage/framework/views
"

print_lines() {
    printf '%s\n' "$1" | sed '/^[[:space:]]*$/d' | sed 's/^/  - /'
}

if [ "$DRY_RUN" -eq 1 ]; then
    echo "DRY-RUN: no se modificará la base de datos ni el filesystem."
    echo "Tablas preservadas explícitamente:"
    print_lines "$PRESERVED_TABLES"
    echo "Tablas operativas candidatas a truncar si existen:"
    print_lines "$CLEANUP_TABLES"
    echo "Directorios/archivos operativos candidatos a limpiar:"
    print_lines "$TARGET_DIRS"
    echo "Backups SQL/dump en la raíz del proyecto hasta profundidad 2."
    exit 0
fi

# ------------------------------------------------------------
# 1) Limpieza de base de datos demo/presentación
# ------------------------------------------------------------

DB_HOST_VALUE="${DB_HOST:-127.0.0.1}"
DB_PORT_VALUE="${DB_PORT:-5432}"
DB_NAME_VALUE="${DB_DATABASE:?DB_DATABASE no está configurado}"
DB_USER_VALUE="${DB_USERNAME:?DB_USERNAME no está configurado}"
export PGPASSWORD="${DB_PASSWORD:-}"

USE_DOCKER=0
if command -v docker >/dev/null 2>&1 && [ -f docker-compose.yml ] && docker compose ps -q db >/dev/null 2>&1; then
    if [ -n "$(docker compose ps -q db)" ]; then
        USE_DOCKER=1
    fi
fi

TABLE_VALUES=$(printf '%s\n' "$CLEANUP_TABLES" | sed '/^[[:space:]]*$/d' | sed "s/'/''/g" | sed "s/.*/('&'),/" | sed '$ s/,$//')

SQL=$(cat <<SQL
DO \$\$
DECLARE
    tables text;
BEGIN
    WITH requested(table_name) AS (
        VALUES
        $TABLE_VALUES
    )
    SELECT string_agg(format('%I', t.table_name), ', ' ORDER BY t.table_name)
      INTO tables
    FROM requested r
    JOIN information_schema.tables t
      ON t.table_schema = 'public'
     AND t.table_type = 'BASE TABLE'
     AND t.table_name = r.table_name;

    IF tables IS NULL THEN
        RAISE NOTICE 'No hay tablas operativas de presentación para truncar con el inventario actual.';
        RETURN;
    END IF;

    EXECUTE format('TRUNCATE TABLE %s RESTART IDENTITY CASCADE', tables);
    RAISE NOTICE 'Truncadas tablas operativas de presentación: %', tables;
END \$\$;
SQL
)

echo "Limpiando base de datos (${DB_NAME_VALUE}) con inventario explícito de presentación..."

if [ "$USE_DOCKER" -eq 1 ]; then
    printf '%s\n' "$SQL" | docker compose exec -T db psql -U "$DB_USER_VALUE" -d "$DB_NAME_VALUE" -v ON_ERROR_STOP=1
else
    printf '%s\n' "$SQL" | psql -h "$DB_HOST_VALUE" -p "$DB_PORT_VALUE" -U "$DB_USER_VALUE" -d "$DB_NAME_VALUE" -v ON_ERROR_STOP=1
fi

# ------------------------------------------------------------
# 2) Limpieza de archivos para demo
# ------------------------------------------------------------

echo "Limpiando archivos de storage para presentación..."

printf '%s\n' "$TARGET_DIRS" | sed '/^[[:space:]]*$/d' | while IFS= read -r dir; do
    if [ -d "$dir" ]; then
        find "$dir" -mindepth 1 -delete
    fi
done

echo "Limpiando respaldos (archivos .sql/.dump/.backup/.gz en root del proyecto)..."
find . -maxdepth 2 -type f \( -name "*.sql" -o -name "*.sql.gz" -o -name "*.dump" -o -name "*.backup" -o -name "*.bak" \) -delete

echo "Limpieza finalizada ✅"
echo "Empleados y configuración laboral base fueron preservados."
