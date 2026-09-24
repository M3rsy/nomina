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

Los componentes reutilizables actuales son mínimos:

- `resources/views/components/auth/shell.blade.php`;
- `resources/views/components/layouts/app.blade.php`;
- `resources/views/components/ui/loading-button.blade.php`;
- `resources/views/components/ui/loading-overlay.blade.php`.

`app/View/Components/AppLayout.php` concentra navegación, estado activo de rutas, visibilidad por permisos, usuario actual, rol, empresa actual y empresas disponibles para `super_admin`. `app/Providers/AppServiceProvider.php` complementa esa información para vistas Livewire que usan `components.layouts.app` directamente.

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
