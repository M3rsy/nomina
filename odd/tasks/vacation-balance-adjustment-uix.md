# Vacation balance adjustment UIX

## Goal
Redesign the `/vacaciones` balance-adjustment modal so operators can understand the current balance, signed adjustment, projected balance, and audit consequence before saving.

## Issue
- Approved issue: [#384](https://github.com/M3rsy/nomina/issues/384).

## References
- User-provided desktop capture: `/tmp/pi-clipboard-f18402b0-cccc-4437-8867-0d6f213c3277.png` (1600×1280).
- User-provided mobile capture: `/tmp/pi-clipboard-f37e3f2c-bc1b-4928-8d4c-1f9a467d7a52.png` (433×636).
- Existing approval modal and repository design tokens remain the implementation reference.

## Scope
- Bring the adjustment modal into the visual hierarchy of the approval modal.
- Preserve employee search/selection, validation, loading, Escape, focus, and authorization behavior.
- Show current balance, signed variation, and projected balance before submission.
- Distinguish credits and deductions visually without changing persistence behavior.
- Present the required reason as audit evidence.
- Keep the modal usable on desktop and mobile.

## Non-goals
- Changing balance calculations, validation rules, or append-only persistence.
- Changing permissions, tenant scope, approval, cancellation, or history behavior.
- Adding new backend workflows.

## Tasks
- [x] 1. Add a failing presentation regression for modal hierarchy, accessibility, and preserved Livewire bindings; implement the structural redesign.
- [x] 2. Add failing behavior coverage for positive/negative projected-balance previews; implement the impact summary and audit guidance.
- [x] 3. Run focused and full verification, build, formatting, diagnostics, independent review, and publish an issue-linked PR for user QA without merging.

## Acceptance criteria
- The modal has a distinct header, body sections, close control, and footer consistent with the approval modal.
- The selected employee's current balance is visible.
- Positive and negative adjustments show signed variation and exact projected balance.
- Negative projected balances are represented correctly.
- The reason is clearly identified as required audit evidence.
- Existing actions, validation messages, loading target, focus, Escape behavior, and ARIA semantics remain intact.
- Presentation tests protect desktop/mobile-friendly structure and public Livewire behavior.

## Evidence
- Structure RED: missing `data-vacation-modal="balance-adjustment"`; GREEN: 1 test, 27 assertions.
- Preview RED: missing balance-value markers; GREEN covers credit, deduction, and negative projection.
- Independent verification found and blocked non-reactive plain `wire:model` bindings.
- Reactive correction uses `wire:model.live="employeeId"` and `wire:model.live.debounce.300ms="adjustmentDays"`.
- Corrected focused gate: 22 tests, 174 assertions; build, Pint, diff check, and Tailwind class audit passed.
- Full regression gate: 1,102 passed, 3 skipped, 5,934 assertions.
- Production build, Pint, `git diff --check`, and LSP diagnostics passed.
- Authored review size: 245 lines including tests and this tracking artifact, below the 400-line limit.
- Delivery: issue-linked work-unit commit and PR prepared for user QA; merge explicitly deferred.
- Empty and zero previews remain neutral; zero still fails existing save validation.

## Rollback boundary
Revert the adjustment-modal markup and its focused presentation tests. No service, database, or migration rollback is required.
