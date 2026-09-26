# Stitch Employee Create

## Goal

Migrate only the new employee screen (`/empleados/crear`) toward the Google Stitch form design while preserving Livewire behavior and backend logic.

## Chain context

- Prior dashboard and employees index PRs have been merged to `main`.
- This slice starts from `origin/main` on branch `feat/stitch-employee-create-ui`.

## Non-goals

- Do not modify business logic.
- Do not modify services, models, payroll rules, policies, authorization, routes, or processing.
- Do not modify employee edit behavior in this slice.
- Do not introduce React, Vue, Next.js, CDN Tailwind, Lucide, Material Symbols, or another frontend framework.
- Do not copy Stitch HTML indiscriminately.

## Constraints

- Preserve Livewire `wire:submit="save"` and all field bindings.
- Preserve company selection, schedule selection, validation errors, and save behavior.
- Preserve cancellation route back to `/empleados`.
- Preserve accessibility labels, error announcements, and keyboard navigation.
- Keep all existing tests passing.
- Use TDD: behavior test first, then Blade changes.

## Status

Complete — implementation and focused automated validation passed.

## Tasks

- [x] Add/adjust Feature coverage for the Stitch-inspired new employee form structure.
- [x] Migrate `resources/views/livewire/empleados/create.blade.php` using existing components and semantic tokens.
- [x] Run focused employee/UI tests.
- [x] Record verification evidence and browser-review risks.

## Evidence

- Branch: `feat/stitch-employee-create-ui`
- Scope: `/empleados/crear` only.
- RED: `php artisan test tests/Feature/Empleados/EmployeeCrudTest.php --filter='employee create presents the registration workflow in semantic sections'` failed because the previous view did not render the new breadcrumb and section contract.
- GREEN: the same focused test passed with 1 test and 16 assertions after the Blade migration.
- Verification: `php artisan test tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 33 tests and 197 assertions.

## Pending checks and risks

- Browser review remains pending for responsive card balance, sticky action-bar behavior, and focus visibility at mobile and desktop widths.
- No backend, shared employee partial, route, or dependency files were changed.
