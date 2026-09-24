# Stitch Payroll Index

## Goal

Migrate only the payroll periods index (`/nomina`) toward the Google Stitch payroll module design while preserving Livewire behavior and payroll logic.

## Non-goals

- Do not modify business logic.
- Do not modify services, models, payroll rules, policies, authorization, routes, or processing.
- Do not modify `/nomina/{period}/revisar` or `/nomina/{period}/procesar` in this slice.
- Do not introduce React, Vue, Next.js, CDN Tailwind, Material Symbols, or another frontend framework.
- Do not copy Stitch HTML indiscriminately.
- Do not invent metrics not exposed by the current Livewire component.

## Constraints

- Preserve `wire:click="openCreateForm"`, `wire:submit="store"`, `wire:click="closeCreateForm"`, and delete confirmation behavior.
- Preserve no-active-company guided state.
- Preserve permissions for create/upload/review/delete actions.
- Preserve period status presentations and payroll workflow component.
- Preserve responsive desktop/mobile period lists and pagination.
- Preserve delete modal accessibility and focus behavior.
- Keep all existing tests passing.
- Use TDD: behavior test first, then Blade changes.

## Tasks

- [x] Add/adjust Feature coverage for the Stitch-inspired payroll index structure.
- [x] Migrate `resources/views/livewire/nomina/index.blade.php` using existing components and semantic tokens.
- [x] Run focused payroll/UI tests.
- [x] Record verification evidence and browser-review risks.

## Evidence

- Branch: `feat/stitch-payroll-index-ui`
- Scope: `/nomina` index only.
- RED: `php artisan test tests/Feature/Nomina/IndexTest.php` failed on the two new presentation contracts (`data-payroll-index` and `data-create-period-steps`); 33 existing tests passed.
- GREEN: `php artisan test tests/Feature/Nomina/IndexTest.php` passed with 35 tests and 223 assertions after the Blade migration.
- Final focused payroll/UI command: `php artisan test tests/Feature/Nomina/IndexTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 48 tests and 337 assertions.
- Browser review: not run; responsive layout, visual hierarchy, and focus behavior still require manual browser confirmation.
