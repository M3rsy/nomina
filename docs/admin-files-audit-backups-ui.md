# Fase F — Administración: archivos, auditoría y respaldos

Issue: [#315](https://github.com/M3rsy/nomina/issues/315). Parent: [#312](https://github.com/M3rsy/nomina/issues/312).

## Estado

La integración actual de archivos, auditoría y respaldos ya usa el patrón visual administrativo compartido suficiente para cerrar esta fase como refactor de estandarización sin cambios funcionales adicionales.

## Superficies auditadas

- `resources/views/livewire/archivos/index.blade.php`
- `resources/views/livewire/archivos/show.blade.php`
- `resources/views/livewire/archivos/upload.blade.php`
- `resources/views/livewire/auditoria/index.blade.php`
- `resources/views/livewire/respaldos/index.blade.php`
- `app/Livewire/Archivos/Index.php`
- `app/Livewire/Archivos/Show.php`
- `app/Livewire/Archivos/Upload.php`
- `app/Livewire/Auditoria/Index.php`
- `app/Livewire/Respaldos/Index.php`

## Contratos conservados

- Archivos conserva parsing, validación, reportes de error, autorización de carga/descarga y aislamiento por empresa/período.
- La carga mantiene el file input accesible y especializado para `.txt`/`.dat`; no se fuerza a un campo genérico.
- Auditoría conserva tipos de evento, filtros, descripciones y semántica histórica sin exponer datos cross-tenant.
- Respaldos conserva acceso global restringido, generación/descarga autorizada, restauración bloqueada/registrada y mensajes seguros sin filtrar detalles internos.
- Las pantallas mantienen headers, filtros, tablas, empty states, feedback visible, loading targets específicos y confirmaciones de acciones sensibles.
- Ninguna vista decide permisos, tenant scope, parsing, auditoría, backup security ni restore safeguards.

## Cobertura vigente

- `tests/Feature/Archivos/IndexTest.php`
- `tests/Feature/Archivos/ShowTest.php`
- `tests/Feature/Archivos/UploadTest.php`
- `tests/Feature/Archivos/ErrorReportDownloadTest.php`
- `tests/Feature/Archivos/UploadedFilePolicyTest.php`
- `tests/Feature/FileIsolationTest.php`
- `tests/Feature/Auditoria/AuditoriaTest.php`
- `tests/Feature/Respaldos/BackupsTest.php`
- `tests/Feature/Fase9/BackupCompletenessTest.php`
- `tests/Feature/Fase9/BackupRuntimeTest.php`
- `tests/Feature/Services/FileValidatorTest.php`
- `tests/Feature/Ui/DesignSystemComponentsTest.php`
- `tests/Feature/Ui/OperationsLoadingFeedbackTest.php`

## Regla de continuación

Cualquier cambio posterior en archivos, auditoría o respaldos debe seguir RED → GREEN → REFACTOR y no puede alterar parsing, autorización de descargas, semántica de auditoría, cifrado/seguridad de respaldos, restore safeguards, tenant scope ni validaciones dentro de un PR visual.
