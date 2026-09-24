# Fase E — Administración: jornadas, feriados y vacaciones

Issue: [#314](https://github.com/M3rsy/nomina/issues/314). Parent: [#312](https://github.com/M3rsy/nomina/issues/312).

## Estado

La integración actual de jornadas, feriados y vacaciones ya usa el patrón visual administrativo compartido suficiente para cerrar esta fase como refactor de estandarización sin cambios funcionales adicionales.

## Superficies auditadas

- `resources/views/livewire/jornadas/index.blade.php`
- `resources/views/livewire/feriados/index.blade.php`
- `resources/views/livewire/vacaciones/index.blade.php`
- `app/Livewire/Jornadas/Index.php`
- `app/Livewire/Feriados/Index.php`
- `app/Livewire/Vacaciones/Index.php`

## Contratos conservados

- Jornadas conserva versionado, publicación efectiva, retiro/reasignación y bloqueo por nómina cerrada.
- Feriados conserva contexto activo de empresa, creación/edición/toggle/delete y validaciones existentes.
- Vacaciones conserva reglas de negocio: saldo negativo permitido, exclusión de descansos/feriados, snapshots pagables, cancelación reversible por historial y bloqueo por períodos de nómina.
- Los controles destructivos o históricos siguen usando confirmaciones/loading targets específicos.
- Las pantallas mantienen estados sin empresa, filtros, tablas semánticas, feedback visible, modales accesibles y mensajes de validación.
- Ninguna vista decide permisos, tenant scope, cálculo de vacaciones, publicación de jornadas ni reglas de calendario.

## Cobertura vigente

- `tests/Feature/Fase3/JornadasFeriadosTest.php`
- `tests/Feature/Jornadas/IndexTest.php`
- `tests/Feature/Jornadas/WorkScheduleProfileRetirerTest.php`
- `tests/Feature/Jornadas/WorkScheduleTest.php`
- `tests/Feature/Feriados/HolidayTest.php`
- `tests/Feature/Attendance/HolidayCalendarTest.php`
- `tests/Feature/Payroll/PaidVacationPayrollIntegrationTest.php`
- `tests/Feature/Vacaciones/VacationDayFactoryTest.php`
- `tests/Feature/Vacaciones/VacationManagerTest.php`
- `tests/Feature/Vacaciones/VacationPolicyTest.php`
- `tests/Feature/Ui/DesignSystemComponentsTest.php`

## Regla de continuación

Cualquier cambio posterior en jornadas, feriados o vacaciones debe seguir RED → GREEN → REFACTOR y no puede alterar publicación/versionado de jornadas, reglas de vacaciones, calendario laboral, policies, tenant scope ni validaciones dentro de un PR visual.
