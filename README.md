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

### Entorno recomendado

- **Windows:** usar WSL2 con Ubuntu y Docker Desktop con la integración de WSL habilitada. Clonar el repositorio dentro del sistema de archivos de Ubuntu y ejecutar ahí los comandos; evitá trabajar desde `/mnt/c` para obtener mejor rendimiento y permisos más predecibles.
- **Linux y macOS:** usar Docker con `docker compose` directamente desde la terminal.

Hay dos plantillas de entorno, según cómo se ejecuten los servicios:

| Entorno | Comando | Host de PostgreSQL |
| --- | --- | --- |
| Docker Compose | `cp .env.docker.example .env` | `DB_HOST=nomina-db` |
| Lerd o servicios nativos | `cp .env.lerd.example .env` | `DB_HOST=lerd-postgres` |

No mezclar las plantillas: los nombres de host dependen de la red de cada entorno.

### Windows: instalación automática con PowerShell y WSL2

Requisitos: Windows con WSL2 y una distribución Ubuntu instalada, Docker Desktop en ejecución con la integración WSL habilitada para esa distribución, y Git disponible dentro de Ubuntu. El script clona el repositorio **dentro del sistema de archivos Linux de WSL** (por defecto en `~/proyectos/nomina`); rechaza rutas bajo `/mnt/`.

Desde una carpeta cualquiera en PowerShell, descargá el script directamente desde la rama `main`, habilitá scripts solo para el proceso actual y ejecutá el modo limpio:

```powershell
Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/M3rsy/nomina/main/scripts/setup-windows.ps1' -OutFile './setup-windows.ps1'
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
./setup-windows.ps1 -InstallMode Clean -AdminEmail admin@example.com
```

No necesitás clonar el proyecto previamente: **el propio script clona el repositorio dentro del sistema de archivos Linux de WSL**, por defecto en `~/proyectos/nomina`. El modo limpio conserva datos, ejecuta migraciones no destructivas, siembra únicamente permisos/roles y solicita la contraseña del super admin sin mostrarla.

Ejemplos adicionales con el archivo descargado:

```powershell
# Datos de demostración
./setup-windows.ps1 -InstallMode Demo

# Instalación limpia e inicio del worker
./setup-windows.ps1 -InstallMode Clean -Worker

# Fetch y pull fast-forward-only explícitos
./setup-windows.ps1 -InstallMode Clean -Update

# Reset destructivo: además exige escribir RESETEAR de forma interactiva
./setup-windows.ps1 -InstallMode Clean -ResetDatabase

# Otra distribución o ubicación Linux
./setup-windows.ps1 -Distro Ubuntu-24.04 -LinuxProjectPath '~/proyectos/nomina'
```

Si ya tenés un checkout de este repositorio en Windows, como alternativa podés ejecutar `./scripts/setup-windows.ps1` desde su raíz. El script conserva un `.env` existente. Si no existe, lo crea desde `.env.docker.example`; también genera `APP_KEY` solo cuando falta y recrea `app` para que Compose cargue la clave. El repositorio solo se actualiza con `-Update`, y ninguna base existente se borra salvo que combines `-ResetDatabase` con la confirmación interactiva.

### Instalación recomendada con Docker

Este flujo es el mismo en Ubuntu sobre WSL2, Linux y macOS:

```bash
git clone <URL_DEL_REPOSITORIO>
cd nomina
cp .env.docker.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose up -d --force-recreate
docker compose exec app php artisan migrate --seed
```

La recreación carga en los contenedores la clave generada en `.env`. Luego abrí http://localhost:8000 e iniciá sesión con alguno de los usuarios de demostración:

- Super admin: `admin@nomina.test` / `password`
- Admin Empresa A: `admin_a@empresa-a.test` / `password`
- Admin Empresa B: `admin_b@empresa-b.test` / `password`

Los puertos de desarrollo se enlazan a `127.0.0.1`, por lo que la aplicación y PostgreSQL no quedan expuestos fuera de la máquina local.

#### Instalación limpia, sin datos de demostración

En lugar de `php artisan migrate --seed`, ejecutá las migraciones, sembrá solamente permisos y roles, y creá un super admin. Cambiá el correo y la contraseña antes de ejecutar el último comando:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=PermissionRoleSeeder
docker compose exec app php artisan tinker --execute='$user = App\Models\User::updateOrCreate(["email" => "admin@example.com"], ["name" => "Super Admin", "password" => Illuminate\Support\Facades\Hash::make("cambiar-esta-clave"), "company_id" => null, "is_active" => true]); $user->syncRoles("super_admin");'
```

Esta variante no crea empresas ni empleados de demostración.

### Comandos operativos

```bash
# Iniciar la aplicación y PostgreSQL
docker compose up -d

# Detener los contenedores
docker compose down

# Seguir los logs de la aplicación
docker compose logs -f app

# Abrir una shell en la aplicación
docker compose exec app sh

# Iniciar el worker de colas
docker compose --profile worker up -d worker
```

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
