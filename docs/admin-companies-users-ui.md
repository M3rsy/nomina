# Fase D — Administración: empresas y usuarios

Issue: [#313](https://github.com/M3rsy/nomina/issues/313). Parent: [#312](https://github.com/M3rsy/nomina/issues/312).

## Estado

La integración actual de empresas y usuarios ya usa el patrón visual administrativo compartido suficiente para cerrar esta fase como refactor de estandarización sin cambios funcionales adicionales.

## Superficies auditadas

- `resources/views/livewire/empresas/index.blade.php`
- `resources/views/livewire/empresas/create.blade.php`
- `resources/views/livewire/empresas/edit.blade.php`
- `resources/views/livewire/empresas/toggle-activate.blade.php`
- `resources/views/livewire/usuarios/index.blade.php`
- `resources/views/livewire/usuarios/create.blade.php`
- `resources/views/livewire/usuarios/edit.blade.php`
- `app/Livewire/Empresas/*`
- `app/Livewire/Usuarios/*`

## Contratos conservados

- Las acciones siguen protegidas por policies/gates; la vista sólo muestra controles permitidos.
- El contexto multi-tenant no se decide en Blade y no se expone como input manipulable.
- La creación/edición de empresas y usuarios conserva validaciones existentes.
- `company_admin` no gana capacidades globales ni puede asignar roles fuera de alcance.
- Las tablas mantienen región desplazable, encabezados semánticos, estado visible y acciones acotadas.
- Los formularios conservan labels, feedback de validación y acciones de guardado con loading scope.

## Cobertura vigente

- `tests/Feature/CompanyIndexPresentationTest.php`
- `tests/Feature/Empresas/CompanyCreationTest.php`
- `tests/Feature/Auth/CompanyAdminCompanyTest.php`
- `tests/Feature/MultiTenant/CompanyIsolationTest.php`
- `tests/Feature/MultiTenant/UserPolicyTest.php`
- `tests/Feature/MultiTenant/CurrentCompanyContextTest.php`
- `tests/Feature/Ui/DesignSystemComponentsTest.php`

## Regla de continuación

Cualquier cambio posterior en empresas/usuarios debe seguir RED → GREEN → REFACTOR y no puede modificar policies, gates, asignación de roles, selección de empresa activa ni aislamiento multi-tenant dentro de un PR visual.
