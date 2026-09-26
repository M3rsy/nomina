# Stitch Super Dashboard

## Goal

Migrate only `/dashboard/super` toward the Google Stitch visual direction using the existing Laravel + Livewire + Blade + Tailwind architecture.

## Non-goals

- Do not modify business logic.
- Do not modify services, models, payroll rules, policies, authorization, routes, or processing.
- Do not introduce React, Vue, Next.js, or another frontend framework.
- Do not migrate `/dashboard/company` in this change.
- Do not copy Stitch HTML indiscriminately.

## Constraints

- Preserve Livewire bindings and URL filters.
- Preserve permissions and multi-company context.
- Preserve accessibility semantics and keyboard navigation.
- Keep all existing tests passing.
- Use TDD: behavior test first, then Blade/CSS changes.

## Tasks

- [x] Add/adjust Feature coverage for the Stitch-inspired super dashboard structure.
- [x] Add semantic dashboard tokens needed for the Stitch visual language.
- [x] Migrate `resources/views/livewire/dashboard/super-admin.blade.php` using existing Blade and Tailwind patterns.
- [x] Run focused tests for dashboard and UI contracts.

## Evidence

- Branch: `feat/stitch-super-dashboard`
- Initial scope: `/dashboard/super` only.
- RED: focused Feature run failed on the four missing dashboard section markers and missing dashboard token families (2 failures, 27 passes).
- GREEN: `php artisan test tests/Feature/Dashboard/DashboardSuperTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed (29 tests, 189 assertions).
- Preserved `from` and `to` Livewire filters, active-company isolation, payroll values, empty states, permission-gated audit handoff, and real application routes.
- No backend or business-logic files changed.

## Pending checks

- Visual browser review at responsive breakpoints and with representative production-sized values was not run in this slice.
- `/dashboard/company` remains a separate follow-up PR.
- No commit was created, per scope constraint.
