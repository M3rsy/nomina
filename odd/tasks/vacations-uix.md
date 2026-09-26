# Vacation management UIX

## Goal
Implement the Stitch-inspired visual redesign for the authenticated vacation management module without changing its Livewire interface, permissions, tenant scoping, business rules, or accessibility contracts.

## Tasks
- [x] Map the Stitch reference and current vacation contracts.
- [x] Redesign the vacation index hierarchy, summary, filters, table, responsive states, and modals.
- [x] Add focused presentation regression coverage while preserving existing vacation tests.
- [x] Run build and vacation/UI verification, inspect the native review candidate, and prepare the integration PR.

## Completion evidence
- Commit: `d1a1dff feat: modernize vacation management UI`
- Native review: approved and acknowledged for target `sha256:74b0f77e31d853684a1ca233e6046662202615505f9c8148701335d96a87eb0d`.

## Progress evidence
- Added `data-vacation-section` markers for hero, summary, filters, and records.
- Preserved all existing Livewire bindings, actions, events, permission gates, pagination, tenant scoping, and modal accessibility behavior.
- Added `tests/Feature/Vacaciones/VacationPresentationTest.php` with management and view-only coverage.
- Focused vacation/payroll verification: 24 tests, 135 assertions passed.
- Vite production build passed.

## Constraints
- Preserve `App\\Livewire\\Vacaciones\\Index` public properties, actions, bindings, events, routes, permissions, and tenant isolation.
- Preserve modal ARIA, focus, Escape handling, validation messages, and pagination.
- Do not alter vacation calculations, policies, manager behavior, payroll integration, or database contracts.
- Use repository design tokens/components where compatible; no CDN, Material Symbols, or Google Fonts.
- Keep technical artifacts in English.

## Evidence
- Stitch reference: https://stitch.withgoogle.com/projects/5126769435574810571?node-id=a73493d3af7a47ff92ca6016e0d0da8f
- Existing implementation: `resources/views/livewire/vacaciones/index.blade.php`
- Component contract: `app/Livewire/Vacaciones/Index.php`
- Existing vacation regression suites under `tests/Feature/Vacaciones/`.
