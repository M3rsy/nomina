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

```bash
php artisan test
```

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
