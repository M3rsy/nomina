# Nómina

Sistema multi-tenant de planilla y asistencia construido con Laravel 12, Livewire 3, Tailwind 4 y PostgreSQL 15.

## Stack

- **Backend:** PHP 8.5, Laravel 12, Livewire 3
- **Frontend:** Tailwind 4, Vite
- **Base de datos:** PostgreSQL 15
- **Tests:** Pest 3
- **Autorización:** spatie/laravel-permission
- **Respaldos:** spatie/laravel-backup
- **Reportes:** phpoffice/phpspreadsheet

## Desarrollo local

1. Copiar variables de entorno:
   ```bash
   cp .env.example .env
   ```

2. Levantar servicios:
   ```bash
   docker compose up -d
   ```

   Para probar el worker de colas local junto con la app y PostgreSQL:
   ```bash
   docker compose --profile worker up -d app db worker
   ```

   Los puertos publicados por el compose de desarrollo se enlazan a `127.0.0.1` para no exponer la app o PostgreSQL fuera de la máquina local.

3. Instalar dependencias y generar clave:
   ```bash
   docker compose exec app composer install
   docker compose exec app php artisan key:generate
   docker compose exec app php artisan migrate --seed
   docker compose exec app npm install
   docker compose exec app npm run dev
   ```

4. Abrir http://localhost:8000 e iniciar sesión con:
   - Super admin: `admin@nomina.test` / `password`
   - Admin Empresa A: `admin_a@empresa-a.test` / `password`
   - Admin Empresa B: `admin_b@empresa-b.test` / `password`

## Tests

The canonical suite always resolves to an in-memory SQLite database. PHPUnit
forces these values, so inherited host or Docker development settings cannot
select a persistent database.

Local:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= composer test
```

Docker (runs in a new disposable app container and does not start the database service):

```bash
docker compose run --rm --no-deps \
    -e APP_ENV=testing \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=:memory: \
    -e DB_URL= \
    app composer test
```

PostgreSQL isolation suite:

```bash
docker compose up -d db
TEST_DB_HOST=127.0.0.1 \
TEST_DB_PORT=55432 \
TEST_DB_DATABASE=nomina_test \
TEST_DB_USERNAME=nomina_test \
TEST_DB_PASSWORD=nomina_test \
NOMINA_ALLOW_DESTRUCTIVE_TEST_DATABASE=nomina_test@postgresql-v1 \
./vendor/bin/pest -c phpunit.postgresql.xml
```

The PostgreSQL suite is intentionally destructive and only runs when the live
connection resolves to the dedicated `nomina_test` database and user. Do not
point `pgsql_testing` at development or production data.

## Limpieza de presentación

Para limpiar datos operativos de una demo sin borrar empleados ni su configuración laboral base:

```bash
./scripts/limpiar-presentacion.sh --dry-run
./scripts/limpiar-presentacion.sh
```

El modo `--dry-run` muestra el inventario explícito de tablas y rutas que serían limpiadas. La ejecución real mantiene la confirmación tipada y el guard de producción; `--force` solo debe usarse en bases desechables o entornos de presentación controlados.

## Reverse proxy trust

By default, local/direct requests do not trust `X-Forwarded-*` headers. In
production behind nginx or another explicit reverse proxy, set
`TRUSTED_PROXIES` to the proxy IPs or CIDR ranges that are allowed to provide
forwarded client metadata:

```env
TRUSTED_PROXIES=172.20.0.0/16,10.0.0.10
```

Do not use wildcard proxy trust unless the application is unreachable except
through the documented proxy boundary. The app accepts only `X-Forwarded-For`,
`X-Forwarded-Host`, `X-Forwarded-Port`, and `X-Forwarded-Proto` from trusted
proxies.

## Estructura del proyecto

- `app/Services/Payroll/` — motor de cálculo de planilla y reglas de horas.
- `app/Services/BandSplitter/` — división de franjas horarias (ordinarias, extras 25%, 50%, 75%, 100%).
- `app/Services/Export/` — generación de Excel con phpoffice/phpspreadsheet.
- `app/Livewire/` — componentes de interfaz.
- `database/seeders/` — datos de demo y producción.

## Roles

| Rol | Descripción |
| --- | --- |
| `super_admin` | Gestiona empresas, usuarios y vee todo cross-empresa. |
| `company_admin` | Administra empleados, archivos, períodos y planilla de su empresa. |

## Despliegue en producción

Ver [`DEPLOY.md`](DEPLOY.md).

## Licencia

MIT
