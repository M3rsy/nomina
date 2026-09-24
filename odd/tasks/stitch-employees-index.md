# Stitch Employees Index

## Goal

Migrate only the employees listing screen (`/empleados`) toward the Google Stitch visual direction, reusing the dashboard design language already introduced in the chained dashboard PRs.

## Chain context

- Intended follow-up PR after the dashboard chain:
  - PR 1: super dashboard
  - PR 2: company dashboard
  - PR 3: employees index
- Base branch for this slice: `feat/stitch-company-dashboard-ui`.
- Working branch: `feat/stitch-employees-ui`.

## Non-goals

- Do not modify business logic.
- Do not modify services, models, payroll rules, policies, authorization, routes, or processing.
- Do not modify employee create/edit forms in this slice.
- Do not introduce React, Vue, Next.js, CDN Tailwind, or another frontend framework.
- Do not copy Stitch HTML indiscriminately.
- Do not invent dashboard metrics that the current Livewire component does not expose.

## Constraints

- Preserve Livewire search and filter bindings.
- Preserve tenant/company scoping.
- Preserve permissions for create, update, activate, and delete actions.
- Preserve nested Livewire action components for activate/delete.
- Preserve responsive table/list behavior and keyboard scroll region.
- Keep all existing tests passing.
- Use TDD: behavior test first, then Blade changes.

## Tasks

- [x] Add Feature coverage for the Stitch-inspired employees index hierarchy and real result data.
- [x] Migrate `resources/views/livewire/empleados/index.blade.php` using existing semantic tokens and real data.
- [x] Preserve search, status filters, clear action, permissions, nested actions, responsive rows, and keyboard scroll region.
- [x] Run the focused employee/UI test command.
- [x] Record verification evidence and browser-review risks.

## TDD evidence

- RED: `php artisan test tests/Feature/Empleados/IndexTest.php --filter='employee directory presents the Stitch-inspired hierarchy with real result data'` failed because the original view did not contain `Directorio oficial`.
- GREEN: the same focused test passed with 14 assertions after the Blade migration.
- TRIANGULATE: the required employee CRUD and design-system tests exposed a regressed `Código de empleado` label; restoring that contract made the complete focused command pass.

## Verification

- `php artisan test tests/Feature/Empleados/IndexTest.php tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Ui/DesignSystemComponentsTest.php`
  - PASS: 38 tests, 219 assertions.

## Review notes and pending checks

- The view now uses only current paginator/filter/search/employee data for its summary cards and chips.
- Authorization directives and nested Livewire action components remain in place.
- No backend or business-logic files were changed.
- Applied a final visual adjustment to widen the `Personal registrado / Empleados` directory header block (`sm:min-w-72`) after review of the provided screenshot.
- Post-adjustment focused command passed again: `php artisan test tests/Feature/Empleados/IndexTest.php tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` (38 tests, 219 assertions).
- Native review was re-run and acknowledged after the adjustment: `review-4769d97048d916ba`.
- Pending manual browser review: verify horizontal table scrolling, mobile row rhythm, long employee/company names, pagination spacing, and avatar initials with varied names.
- No commit was created, per slice instructions.
