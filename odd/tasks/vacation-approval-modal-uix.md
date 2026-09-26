# Vacation approval modal UIX

## Goal
Implement the Stitch-inspired approval modal presentation as a separate stacked PR on top of the vacation management UI.

## Tasks
- [x] Map the reference modal to the existing Livewire approval contract.
- [x] Redesign the approval modal hierarchy and action footer with real bindings and validation states.
- [x] Add focused presentation coverage for the approval modal.
- [x] Verify, review, commit, and open the issue/PR.

## Completion evidence
- Commit: `251294a feat: redesign vacation approval modal`.
- Focused verification: 17 tests, 107 assertions passed.
- Vite build and LSP diagnostics passed.
- Native review inspection completed for target `sha256:95f48c7dd7db70046d092389dd53e0dfaaf3a6683ed27a5103362112cceb3c41`; `review.start` is available and awaits the explicit lifecycle continuation.

## Progress evidence
- Added Stitch-inspired approval modal hierarchy with real employee search/select, date range, notes, validation, and loading action.
- Preserved server-side calculation authority instead of copying static demo metrics.
- Focused vacation verification: 17 tests, 107 assertions passed.
- Vite production build and LSP diagnostics passed.

## Constraints
- Preserve `openCreateModal`, `closeCreateModal`, `approve`, all `wire:model` bindings, picker search, error keys, ARIA labels, focus, and Escape handling.
- Do not fake the live calculation summary from the static reference; server-side vacation calculations remain authoritative.
- No CDN, Google Fonts, or changes to `VacationManager`, policies, tenant scoping, or payroll rules.
- Keep this PR stacked on `feat/vacations-uix` and limited to the approval modal module.

## Evidence
- Reference: Stitch project node `880571dff29d40639cb55267c7295a24`.
- Issue: #363.
