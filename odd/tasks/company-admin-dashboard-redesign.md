# Company admin dashboard redesign

## Goal

Bring the company admin dashboard in line with the executive visual hierarchy used by the super admin dashboard without changing behavior.

## Tasks

- [x] Restyle the executive hero and date range panel.
- [x] Restyle KPI cards and permission-gated quick actions.
- [x] Restyle the payroll table while preserving its named keyboard-scroll region.
- [x] Restyle recent files and activity cards while preserving empty states and permission gates.
- [x] Run the required build and focused dashboard test.

## Constraints

- Preserve Livewire bindings, variables, routes, permission gates, wording, semantic table structure, and accessibility regions.
- Do not change `app/Livewire/Dashboard/CompanyAdmin.php`.
- Use local design tokens and inline SVG only.

## Evidence

- `resources/views/livewire/dashboard/company-admin.blade.php` now follows the super-admin executive hierarchy: hero, range panel, KPI cards, quick actions, payroll table, and split recent-content cards.
- Existing `wire:model.live` date bindings, route links, authorization directives, data variables, empty states, table columns, and accessible named keyboard-scroll region remain in place.
- `npm run build`: passed; Vite built 55 modules successfully.
- `php artisan test --filter=DashboardCompanyTest`: passed; 11 tests and 38 assertions.
