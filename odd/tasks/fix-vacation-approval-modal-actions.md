# Fix vacation approval modal actions

Feature: fix-vacation-approval-modal-actions

Approved issue: #371

## Root cause

The approval modal closes its `data-vacation-modal="approval"` container before rendering the footer. As a result, the `Cerrar` and `Aprobar vacaciones` actions are sibling content outside the modal card and appear visually misplaced.

## Tasks

- [x] Add a presentation regression test proving both footer actions belong to the approval modal container.
- [x] Move the action footer inside the approval modal card without changing Livewire actions.
- [x] Run focused vacation modal verification.

## Constraints

- Delivery remained blocked until the user completed visual QA and explicitly approved the issue, commit, and pull request.
- Preserve `closeCreateModal`, `approve`, keyboard dismissal, and accessibility attributes.

## Evidence

- RED: `php artisan test tests/Feature/Vacaciones/VacationApprovalModalPresentationTest.php` failed as expected because the XPath found 0 matching descendant footer buttons instead of 1 at line 49.
- GREEN: `php artisan test tests/Feature/Vacaciones/VacationApprovalModalPresentationTest.php` passed with 1 test and 21 assertions after moving the footer inside the approval modal card.
- `git diff --check` passed with no output.
- `php artisan view:cache` passed: Blade templates cached successfully.
- Visual QA was approved by the user before delivery.
