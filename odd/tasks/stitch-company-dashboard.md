# Stitch Company Dashboard

## Goal

Migrate only `/dashboard/company` toward the Google Stitch dashboard visual direction, reusing the design language introduced by the super dashboard slice.

## Chain context

- This slice is intended to become a follow-up chained PR after `feat/stitch-super-dashboard`.
- It should reuse the semantic dashboard tokens already introduced for the super dashboard.
- Keep the company dashboard reviewable independently at the file/test level.

## Non-goals

- Do not modify business logic.
- Do not modify services, models, payroll rules, policies, authorization, routes, or processing.
- Do not introduce React, Vue, Next.js, CDN Tailwind, or another frontend framework.
- Do not rewrite `/dashboard/super` in this slice.
- Do not copy Stitch HTML indiscriminately.

## Constraints

- Preserve Livewire bindings and URL filters.
- Preserve company scoping and active-company empty state.
- Preserve permissions and quick-action visibility.
- Preserve accessibility semantics and keyboard navigation.
- Keep all existing tests passing.
- Use TDD: behavior test first, then Blade/CSS changes.

## Tasks

- [x] Add/adjust Feature coverage for the Stitch-inspired company dashboard structure.
- [x] Migrate `resources/views/livewire/dashboard/company-admin.blade.php` using existing dashboard visual language.
- [x] Run focused tests for company dashboard and UI contracts.
- [x] Record evidence and remaining browser-review risks.

## Evidence

- Branch/worktree base: `feat/stitch-super-dashboard`
- Scope: `/dashboard/company` only.
- RED: `php artisan test tests/Feature/Dashboard/DashboardCompanyTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` failed on the new company hierarchy contract because `Alcance de empresa` was absent; 22 tests passed and 1 failed.
- GREEN: the same focused command passed 23 tests with 135 assertions after the Blade migration.
- Independent verification passed with minor caveats; table headers were then tightened with explicit `scope="col"` attributes.
- Post-adjustment focused command passed again: `php artisan test tests/Feature/Dashboard/DashboardCompanyTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` (23 tests, 135 assertions).
- Scope check: no company backend, route, policy, model, service, or payroll-processing file was changed by this slice.
- CSS: no new rules or tokens were needed; the company dashboard reuses the semantic dashboard tokens from the preceding super dashboard slice.

## Pending checks and risks

- Browser review remains pending for responsive layout, dense payroll tables, long company/file names, and focus presentation in the target browsers.
- No commit was created, per instruction.
