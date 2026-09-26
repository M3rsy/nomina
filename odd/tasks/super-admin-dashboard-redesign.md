# Super Admin Dashboard Redesign

## Goal
Adapt the super admin dashboard view toward the provided executive visual style after the sidebar layout has been committed.

## Scope
- Redesign only `resources/views/livewire/dashboard/super-admin.blade.php`.
- Preserve existing Livewire data, route permissions, filters, empty states, and dashboard behavior.
- Use existing design tokens/components where practical; add no external CDN dependencies.
- Keep `company-admin` and the global layout untouched in this work unit.

## Non-goals
- Do not change queries or domain calculations in `app/Livewire/Dashboard/SuperAdmin.php` unless required by a failing existing contract.
- Do not redesign company admin dashboard.
- Do not introduce Material Symbols or Google Fonts dependencies.

## Tasks
- [x] Implement super admin dashboard visual refresh.
- [x] Verify build and focused dashboard tests.
- [ ] Native review remains parent-owned.

## Evidence
- Branch: `feat/left-sidebar-layout`
- Parent layout commit: `6f86cf1 feat: add authenticated left sidebar layout`
- Dashboard view: executive hero, analysis range panel, organization KPI cards, compact payroll status tiles, audit handoff, and monthly trend split implemented.
- Preserved contracts: `wire:model.live="from"`, `wire:model.live="to"`, payroll and audit permission gates, all payroll empty states, unknown-state alert, and trend data semantics.
- `npm run build` passes.
- `php artisan test --filter=DashboardSuperTest` passes (16 tests, 91 assertions).
- `php artisan test --filter=AuthenticatedNavigationTest` passes (8 tests, 93 assertions).
- `lens_diagnostics` on `resources/views/livewire/dashboard/super-admin.blade.php` reports no diagnostics.
- Commits: pending.
