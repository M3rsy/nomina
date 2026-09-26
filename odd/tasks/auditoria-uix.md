# Audit system UIX

## Goal
Implement the Stitch-inspired forensic audit workspace at `/auditoria` as a separate feature/PR while preserving the existing read-only audit data contract.

## Tasks
- [x] Map the current audit component, view, route, policy, and regression contracts.
- [x] Redesign the audit workspace hierarchy, summary, filters, event table, pagination, and compliance notice.
- [x] Add focused presentation regression coverage.
- [x] Run audit/UI verification, inspect the native review candidate, and prepare the issue/PR.

## Completion evidence
- Commit: `f5e1f88 feat: modernize audit system UI`
- Audit verification: 26 tests, 105 assertions passed.
- Vite production build passed.
- Native review: approved and acknowledged for target `sha256:9ba955de09d013ab1fcc995c900ea942ddca721cd20a061a8b5b20909fc40ec0`.

## Progress evidence
- Added dynamic summary metrics for total events, visible page entries, and active filter state.
- Preserved all existing Livewire URL bindings, projected/legacy fallback, tenant scoping, permissions, read-only behavior, and pagination.
- Added `tests/Feature/Auditoria/AuditoriaPresentationTest.php` with real audit data and permission coverage.
- Audit verification: 26 tests, 105 assertions passed.
- Vite production build passed.

## Constraints
- Preserve `App\\Livewire\\Auditoria\\Index` properties, URL bindings, filters, projected/legacy fallback, tenant scoping, permissions, and pagination.
- Use the existing authenticated app layout and repository design tokens; do not duplicate the global sidebar.
- Do not copy the reference CDN, Google Fonts, static demo values, fake actions, or unsupported export/retention behavior.
- Keep audit records read-only and technical artifacts in English.

## Evidence
- Reference supplied as Stitch HTML and screenshots by the user.
- Existing component: `app/Livewire/Auditoria/Index.php`.
- Existing view: `resources/views/livewire/auditoria/index.blade.php`.
- Existing coverage: `tests/Feature/Auditoria/AuditoriaTest.php`.
- Issue: #361.
