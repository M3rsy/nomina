# Left Sidebar Layout

## Goal
Integrate a global authenticated left sidebar layout while keeping dashboard page content unchanged.

## Scope
- Replace the desktop top navbar in `resources/views/components/layouts/app.blade.php` with a left sidebar shell.
- Keep a mobile top navigation/menu for small screens.
- Reuse existing authenticated layout data and permissions from `app/View/Components/AppLayout.php`.
- Add only global theme tokens needed for the shell in `resources/css/app.css` if necessary.

## Non-goals
- Do not redesign `resources/views/livewire/dashboard/super-admin.blade.php`.
- Do not redesign dashboard cards or page-specific content.
- Do not change authorization rules or route names.

## Tasks
- [x] Implement authenticated sidebar shell.
- [x] Verify Blade/Tailwind build and basic diagnostics.

## Evidence
- Branch: `feat/left-sidebar-layout`
- `npm run build` passes.
- `php artisan test --filter=AuthenticatedNavigationTest` passes (8 tests, 93 assertions).
- `lens_diagnostics` on `resources/views/components/layouts/app.blade.php` reports no diagnostics.
- Full `php artisan test` reached the navigation suite with two initial layout-contract failures; both pass after compatibility updates. The broader run then exhausted the 128 MB PHP memory limit in `ComprobanteDownloadTest` before completion.
- Commits: pending; user has not explicitly authorized commits in this session.
