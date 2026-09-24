# Modernización progresiva de UI/UX

Guía operativa para preparar y ejecutar la modernización visual del proyecto Nómina sin cambiar reglas de negocio, cálculo de nómina ni límites multi-tenant.

Issue de inicio: [#304](https://github.com/M3rsy/nomina/issues/304).

## Alcance

Esta iniciativa cubre únicamente la modernización progresiva de experiencia visual y estructura de interfaz:

- documentación del workflow de trabajo;
- identificación de puntos de entrada UI;
- extracción futura de componentes Blade/Livewire puramente visuales;
- estandarización futura de patrones como encabezados, tarjetas, badges, tablas, filtros, formularios, alertas, modales y estados vacíos;
- protección del baseline de pruebas antes de cada cambio visual.

La primera fase no rediseña pantallas ni modifica comportamiento visible. Sólo establece el baseline y las reglas de trabajo.

## Fuera de alcance

No pertenecen a esta iniciativa:

- lógica de cálculo de nómina;
- reglas de asistencia;
- políticas salariales;
- jobs de procesamiento;
- migraciones;
- cambios de autorización o permisos;
- cambios funcionales de Livewire;
- cambios en `openspec/changes/flexible-single-shift-payroll`.

Si una mejora visual necesita tocar comportamiento, debe separarse en otro Issue y evaluarse con TDD y, si corresponde, OpenSpec/SDD.

## Arquitectura UI actual

La aplicación usa Laravel 12, Livewire 3, Blade, Alpine.js y Tailwind CSS 4. Las rutas de `routes/web.php` montan pantallas públicas de autenticación y pantallas autenticadas para dashboards, empresas, usuarios, empleados, jornadas, feriados, vacaciones, archivos, nómina, auditoría, respaldos y perfil.

Los componentes reutilizables disponibles son:

- Shells y layout: `components/auth/shell.blade.php`, `components/layouts/app.blade.php`.
- Navegación autenticada: `app/View/Components/AppLayout.php` concentra estado activo de rutas, visibilidad por permisos, usuario actual, rol, empresa actual y empresas disponibles para `super_admin`; `app/Providers/AppServiceProvider.php` complementa esa información para vistas Livewire que usan `components.layouts.app` directamente.
- Componentes base: `x-ui.button`, `x-ui.loading-button`, `x-ui.badge`, `x-ui.card`, `x-ui.page-header`, `x-ui.stat-card`.
- Feedback y estados: `x-ui.alert`, `x-ui.feedback`, `x-ui.empty-state`, `x-ui.loading-overlay`.
- Formularios: `x-ui.form-field`, `x-ui.input`, `x-ui.password-field`, `x-ui.select`, `x-ui.textarea`.
- Nómina: `x-nomina.payroll-workflow` para presentar fases de período sin navegación ni cambios de estado.

### Fase 6 — Workflow visual de nómina

Issue de formalización: [#320](https://github.com/M3rsy/nomina/issues/320).

Estado: la modernización de workflow de nómina ya está integrada en `main` y queda formalizada como fase crítica cerrable mediante revisión dedicada. El alcance implementado cubre:

- `PayPeriodStatusPresentation` como seam de presentación para estados, fases, tonos y copy sin autorizar acciones;
- `x-nomina.payroll-workflow` como orientación visual de cinco fases sin navegación ni mutaciones;
- overview de períodos en `livewire/nomina/index.blade.php` con estados, acciones permitidas, empty states y layouts desktop/mobile;
- resultados/finalización en `livewire/nomina/procesar.blade.php` con confirmación accesible de aprobación;
- progreso de procesamiento de nómina y lotes de overtime con feedback live, polling acotado y estados terminales;
- panel de overtime review modernizado sin modificar los archivos congelados `app/Livewire/Nomina/Revisar.php` ni `resources/views/livewire/nomina/revisar.blade.php`.

Contratos protegidos:

- `tests/Unit/Support/Nomina/PayPeriodStatusPresentationTest.php` cubre mapeos de estado y fallback desconocido.
- `tests/Feature/Nomina/IndexTest.php`, `VistaPreviaTest.php`, `AprobarNominaTest.php`, `StartPayrollProcessingTest.php` y `AttendanceReviewTest.php` cubren overview, aprobación, progreso, bloqueo, tenant scope y matrices de acciones.
- `tests/Feature/Payroll/PayrollRunProgressTest.php` y `tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` cubren polling, progreso, terminales y lotes.
- `tests/Feature/Ui/DesignSystemComponentsTest.php` cubre workflow y componentes compartidos.

Regla de continuación: todo cambio posterior en nómina debe conservar que la presentación no decida permisos ni transiciones. Cualquier cambio en cálculo, locking, jobs, policies o PostgreSQL queda fuera de esta fase y requiere issue/especificación separada.

## Componentes y patrones UI

- Usar tokens semánticos de `resources/css/app.css` (`brand`, `surface`, `border`, `text`, `success`, `warning`, `danger`) antes de agregar colores Tailwind hardcoded.
- Preferir `x-ui.button` para acciones y enlaces con apariencia de botón. Los enlaces deshabilitados deben retirar `href`, exponer `aria-disabled="true"` y salir del tab order.
- Usar `x-ui.loading-button` para acciones Livewire mutantes; el `target` debe apuntar a la acción específica para evitar que un loading global bloquee controles no relacionados.
- Usar `x-ui.loading-overlay` sólo para operaciones de página o panel claramente bloqueantes, con mensaje visible y región anunciable.
- Usar `x-ui.alert`/`x-ui.feedback` para mensajes de éxito, advertencia y error. Errores que requieren atención usan `role="alert"`; mensajes informativos usan `role="status"`/`aria-live="polite"`.
- Usar `x-ui.empty-state` para colecciones vacías o filtros sin resultados; el texto debe explicar el próximo paso permitido.
- En tablas anchas, envolver con una región desplazable nombrada para teclado (`aria-label` o `aria-labelledby`) y conservar encabezados semánticos `<th>`.
- En modales, exponer `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, foco inicial programático y cierre por Escape. Si el modal muta datos, usar botones de loading action-scoped.

## Cómo agregar nuevas pantallas

1. Confirmar que el cambio es visual. Si toca cálculo de nómina, permisos, multi-tenancy, asistencia, jobs o migraciones, crear Issue/alcance separado.
2. Montar la pantalla dentro de `x-layouts.app` para heredar navegación, skip link, contexto de empresa y landmarks.
3. Empezar con `x-ui.page-header`; después organizar contenido en `x-ui.card`, estados vacíos y tablas/regiones nombradas.
4. Validar roles desde el inicio: `super_admin` sin empresa, `super_admin` con empresa activa, cambio de empresa, `company_admin` con empresa asignada y accesos prohibidos.
5. Validar responsive por estructura, no por duplicar lógica: desktop, tablet y mobile deben renderizar las mismas acciones autorizadas y el mismo contexto activo.
6. Agregar loading, error, empty, disabled, processing/processed/locked y danger states donde el flujo ya los tenga. No inventar estados nuevos en PRs visuales.

## Cómo escribir tests UI

- Para componentes Blade puros, usar `Blade::render()` y proteger contratos observables: clases semánticas, roles, `aria-*`, atributos Livewire pass-through y contenido visible.
- Para pantallas Livewire, usar `Livewire::test()` para abrir estados, disparar acciones y verificar copy, permisos, loading targets, errores y estados vacíos.
- Para navegación y multi-tenancy, usar tests Feature HTTP con usuarios `super_admin` y `company_admin`; verificar rutas visibles/ocultas, `aria-current`, selector de empresa y restricciones.
- Todo bug corregible automáticamente debe seguir RED → GREEN: primero una prueba que falle por el contrato roto, después el cambio mínimo, luego refactor si aporta claridad.
- Los tests de accesibilidad deben cubrir asociación label/control, `aria-describedby`, `aria-invalid`, live regions, disclosure `aria-expanded`/`aria-controls`, dialog semantics, Escape y foco inicial cuando aplique.
- Los tests responsive automatizables deben proteger markup y estados compartidos; las comprobaciones visuales por breakpoint se documentan en el PR con el dispositivo usado.

## Cómo crear nuevos componentes

1. Crear el componente en `resources/views/components/ui/` sólo si elimina duplicación real o protege un patrón repetido.
2. Exponer una interfaz pequeña: props de intención (`variant`, `tone`, `size`, `target`, `label`, `hint`, `error`) antes que props de clases sueltas.
3. Permitir `$attributes` para IDs, `wire:*`, `aria-*`, `data-*` y clases adicionales. No descartar atributos Livewire.
4. Definir defaults accesibles: `type="button"` para botones no submit, `aria-invalid`, `aria-describedby`, live region correcto, foco visible y estados disabled.
5. Agregar o actualizar un test en `tests/Feature/Ui/DesignSystemComponentsTest.php` o un archivo UI específico antes de migrar pantallas.
6. Documentar el patrón aquí si el componente introduce una regla nueva.

## Qué NO debe duplicarse

- Tokens de color semánticos, clases de foco visible y variantes de botón/badge/alert.
- Lógica de navegación, permisos, contexto activo de empresa o filtros por tenant.
- Spinners, overlays, loading labels y regiones live por acción.
- Formularios con label/error/hint hechos a mano cuando `x-ui.input`, `x-ui.select`, `x-ui.textarea`, `x-ui.password-field` o `x-ui.form-field` cubren el caso.
- Empty states, danger states y copy de bloqueo/locked repetidos sin componente o helper claro.
- Consultas o cálculos para mostrar una métrica si ya existe un servicio/proyección usado por otra pantalla.
- IDs de controles, `aria-controls` o `aria-labelledby` generados con el mismo valor en más de una instancia de la página.

## Estado por fases

### Fase 2 — Application shell / navegación

Issue de formalización: [#319](https://github.com/M3rsy/nomina/issues/319).

Estado: la implementación histórica en `components/layouts/app.blade.php` satisface la fase de shell/navegación sin requerir un rediseño adicional en esta integración. La app usa un shell autenticado con navegación superior sticky y panel móvil, no un sidebar persistente; se considera equivalente funcional para el alcance actual porque cubre:

- skip link a `#main-content` y landmark `<main>` enfocable;
- navegación primaria y grupo de gestión con `aria-current="page"`;
- visibilidad por permisos/rol desde `AppLayout` sin duplicar autorización en Blade;
- contexto de empresa para `super_admin`, incluyendo selector explícito y estado `aria-current="true"`;
- contexto fijo para `company_admin` sin selector cross-tenant;
- navegación móvil con secciones nombradas, cierre por Escape y limpieza de estado al cambiar breakpoint;
- disclosures nativos con `aria-expanded`, `aria-controls`, foco de retorno, `focusout` y sin roles de menú ARIA incorrectos.

Cobertura vigente:

- `tests/Feature/Navigation/AuthenticatedNavigationTest.php` protege rutas activas, navegación permitida/oculta por rol, selector de empresa, semántica de disclosures, Escape, responsive cleanup, contexto activo y ausencia de navegación autenticada en login.
- `tests/Feature/MultiTenant/CurrentCompanyContextTest.php` protege el cambio explícito de empresa y rechazos de company admin/inputs inválidos.
- `tests/Feature/Dashboard/*` y pantallas de administración/nómina protegen que el shell no cambie acceso, permisos ni tenant scope.

Regla de continuación: cualquier futuro cambio visual de shell/sidebar debe conservar esos contratos antes de agregar estilos o reordenar navegación. No se debe introducir un sidebar persistente sólo por estética si duplica navegación, rompe el selector de empresa o aumenta el coste de revisión.

## Fases propuestas

1. **Baseline y reglas de trabajo**
   - Crear Issue, branch y PR dedicados.
   - Registrar estado inicial de pruebas y build.
   - Documentar archivos sensibles y límites de la iniciativa.

2. **Componentes visuales pequeños**
   - Extraer patrones repetidos sin alterar datos ni acciones.
   - Priorizar componentes de bajo riesgo: encabezados, tarjetas, botones, alertas y estados vacíos.
   - Mantener cada PR chico y revisable.

3. **Pantallas de bajo riesgo**
   - Aplicar los componentes a pantallas con baja densidad de lógica.
   - Proteger cada cambio con pruebas de renderizado o contratos existentes.

4. **Pantallas críticas**
   - Tratar nómina, revisión, procesamiento y exportación como cambios de alto riesgo.
   - Dividir por secciones visuales y validar que no cambien transiciones, permisos ni cálculos.
   - Usar OpenSpec/SDD si el cambio excede una refactorización visual mecánica.

## Estrategia de TDD

Antes de modificar UI:

```bash
composer test
npm run build
```

Registrar siempre:

- cantidad aproximada de pruebas ejecutadas;
- fallos existentes;
- warnings;
- resultado del build;
- diferencia entre `PRE-EXISTING FAILURE` y `REGRESSION CAUSED BY THIS CHANGE`.

Para cambios visuales protegibles con pruebas:

1. **RED**: agregar o ajustar una prueba que falle por el contrato visual deseado.
2. **GREEN**: hacer el cambio mínimo para pasar.
3. **REFACTOR**: limpiar duplicación sin alterar comportamiento.

No se debe corregir silenciosamente un fallo preexistente dentro de un PR visual.

### Baseline inicial

Resultado capturado antes de modificar archivos del proyecto en la rama `chore/ui-modernization-baseline`:

- `composer test`: falla por condiciones preexistentes.
  - Aproximadamente 590 pruebas alcanzadas.
  - 585 pasaron.
  - 4 fallaron en `tests/Feature/Fase9/BackupRuntimeTest.php`.
  - 1 prueba terminó fatalmente por memoria en `tests/Feature/Nomina/ComprobanteDownloadTest.php:63`.
- `npm run build`: pasa con Vite 7.3.6, 55 módulos transformados, sin warnings observados.

Clasificación: `PRE-EXISTING FAILURE` para los fallos de `composer test`, porque existían antes de esta documentación.

## Política de branches

- Nunca trabajar directamente sobre `main`.
- Crear un Issue antes de modificar archivos.
- Usar ramas descriptivas con Conventional Commits, por ejemplo:

```text
chore/ui-modernization-baseline
```

- No hacer push directo a `main`.
- Mantener cada rama enfocada en un alcance revisable.

## Política de Issues

Cada Issue de esta iniciativa debe incluir:

- contexto;
- objetivo;
- alcance;
- fuera de alcance;
- criterios de aceptación;
- riesgos;
- plan de pruebas;
- archivos potencialmente afectados.

Los Issues de UI no deben mezclar cálculo de nómina, asistencia, permisos ni cambios de base de datos salvo que el alcance lo declare explícitamente.

## Política de Pull Requests

Cada PR debe:

- enlazar su Issue con `Closes #<número>`;
- usar Conventional Commits;
- explicar alcance y fuera de alcance;
- listar pruebas ejecutadas;
- incluir screenshots sólo cuando exista cambio visual real;
- pasar por revisión antes de merge;
- evitar PRs grandes que mezclen pantallas o módulos críticos.

Template mínimo:

```markdown
## Summary
## Motivation
## Scope
## Out of scope
## Tests
## Risk
## Screenshots
## Rollback
## Checklist
```

## Archivos y áreas sensibles

No tocar accidentalmente durante una refactorización visual:

- `app/Services/Payroll/`;
- `app/Services/Attendance/`;
- `app/Jobs/ProcessPayrollRun.php`;
- `app/Jobs/ProcessOvertimeDecisionBatch.php`;
- `database/migrations/`;
- lógica de cálculo de nómina;
- políticas salariales;
- reglas de asistencia;
- `openspec/changes/flexible-single-shift-payroll`.

Áreas de especial cuidado:

- componentes Livewire de nómina (`revisar`, `procesar`, exportaciones y aprobaciones);
- usos de `withoutCompanyScope()`;
- route binding de períodos de nómina;
- gates, policies y directivas `@can`;
- contexto de empresa activa para `super_admin` y `company_admin`.

## OpenSpec / SDD

La modernización UI debe usar un cambio OpenSpec separado si el trabajo deja de ser puramente mecánico o documental. El nombre reservado para esa iniciativa es:

```text
openspec/changes/payroll-ui-modernization/
```

No se inicializa en esta fase porque todavía no hay una especificación funcional nueva ni una decisión de diseño aprobada. Bajo ninguna circunstancia debe reutilizarse ni modificarse `openspec/changes/flexible-single-shift-payroll` para trabajo de UI.
