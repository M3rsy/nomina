# Tasks: Payroll UI Modernization

## Review Workload Forecast

| Field | Value |
| ------- | ------- |
| Estimated changed lines | 1,400–2,200 additions + deletions across shared presentation code, five Livewire views/components, and focused tests |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: shared status presentation + Index → PR 2: Procesar + approval confirmation → PR 3: progress components + overtime review panel |
| Delivery strategy | ask-on-risk resolved to chained delivery |
| Chain strategy | feature-branch-chain |

Decision needed before apply: Resolved — implement the three-slice chain.
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

The forecast exceeds the 400-line review budget. The maintainer selected the three-slice chained delivery using the Feature Branch Chain strategy: PR 1 targets the tracker branch, PR 2 targets PR 1, PR 3 targets PR 2, and only the tracker proceeds toward main after the chain is complete. This decision authorizes implementation of the first slice only; later slices remain separate work units.

## Global boundaries

- Modify only application/test files named by these tasks after the delivery decision; never edit `app/Livewire/Nomina/Revisar.php`, `resources/views/livewire/nomina/revisar.blade.php`, or anything under `openspec/changes/flexible-single-shift-payroll/`.
- Preserve the exact ten pay-period statuses and transitions, upload/approval/export matrices, tenant scoping, policies/gates, route parameters, audit behavior, projections, row locking, polling, events, and payloads. Visual phases remain presentation-only.
- Follow strict TDD inside every work unit: RED tests first, GREEN minimum implementation, TRIANGULATE edge cases/invariants, then REFACTOR with the focused suite still green.

## Work Unit 1 — Shared status presentation and period overview

**Start:** Existing inline status presentation and Index behavior are unchanged.
**Finish:** A presentation-only status/workflow seam drives a responsive, permission-correct Index while lifecycle, tenancy, creation, deletion, and navigation behavior remain exact.
**Dependency:** None after the delivery decision.
**Rollback boundary:** Revert `app/Support/Nomina/PayPeriodStatusPresentation.php`, `resources/views/components/nomina/payroll-workflow.blade.php`, any shared dialog added by this slice, Index-only wiring/view changes, and their tests; no data rollback is needed.

### RED

- [x] Add table-driven failing coverage for all ten exact status label/tone/phase/copy mappings, the explicit unknown fallback, and the absence of action/authorization decisions in `tests/Unit/Support/Nomina/PayPeriodStatusPresentationTest.php`; add workflow rendering/accessibility failures in `tests/Feature/Ui/DesignSystemComponentsTest.php`. <!-- sdd-owner: implementation -->
- [x] Extend `tests/Feature/Nomina/IndexTest.php` and `tests/Feature/Archivos/UploadTest.php` with failing protection for tenant-derived `draft` creation, overlap/slug rejection, upload redirect, no client-controlled company/status, the exact upload matrix, `files.upload` and `marks.manage` CTA visibility, direct authorization, route parameters/navigation, no-company guidance, active-company/cross-tenant isolation, and policy-controlled create/delete visibility. <!-- sdd-owner: implementation -->
- [x] Add failing Index presentation tests in `tests/Feature/Nomina/IndexTest.php` for the five-phase orientation, exact status and next-action copy, semantic variants, separate responsive table/card semantics, permitted empty-state actions, and an accessible deletion dialog with labeled reason, safe initial focus, Escape cancellation, focus return, and scoped busy/disabled state. <!-- sdd-owner: implementation -->
- [x] Retain or strengthen deletion regression tests in `tests/Feature/Nomina/IndexTest.php` for cancellation without mutation, required reason and 500-character limit, unchanged eligibility, one-time period/file soft deletion, and audit actor/reason metadata. <!-- sdd-owner: implementation -->

### GREEN

- [x] Create `app/Support/Nomina/PayPeriodStatusPresentation.php` and `resources/views/components/nomina/payroll-workflow.blade.php` with only immutable display metadata and the five descriptive phases; unknown statuses must remain visibly unknown and grant no action. <!-- sdd-owner: implementation -->
- [x] Update `app/Livewire/Nomina/Index.php` to pass presentation objects and permission/model-derived action booleans separately, retaining every direct authorization, tenant query, validation, mutation, audit, and redirect path. <!-- sdd-owner: implementation -->
- [x] Modernize `resources/views/livewire/nomina/index.blade.php` with semantic `x-ui` modules, permission-correct upload/review/create/delete controls, useful no-company/no-period states, responsive period layouts, and the tested deletion-dialog lifecycle; create `resources/views/components/ui/dialog.blade.php` only if it provides the full reusable accessibility seam rather than a shallow wrapper. <!-- sdd-owner: implementation -->

### TRIANGULATE

- [x] Exercise all statuses and permission combinations in `tests/Feature/Nomina/IndexTest.php`, including upload only for `draft`, `uploaded`, and `validation_failed`; no upload for the other seven statuses; hidden review without `marks.manage`; forged cross-tenant IDs; and super-admin active-company context. <!-- sdd-owner: implementation -->

### REFACTOR AND VERIFY

- [ ] Remove duplicated Index status/presentation markup without moving authorization into the presentation module; run `./vendor/bin/pest tests/Unit/Support/Nomina/PayPeriodStatusPresentationTest.php tests/Feature/Nomina/IndexTest.php tests/Feature/Archivos/UploadTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` and record the exact result plus a manual narrow/wide viewport and keyboard-dialog check. <!-- sdd-owner: implementation -->

**WU1 evidence:** Focused verification passed on 2026-09-23: `./vendor/bin/pest tests/Unit/Support/Nomina/PayPeriodStatusPresentationTest.php tests/Feature/Nomina/IndexTest.php tests/Feature/Archivos/UploadTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` → 81 passed, 491 assertions. The local environment attempted to start PostgreSQL and failed before the SQLite/Pest run, but this slice is presentation-only and the focused suite completed successfully.

**WU1 delivery split:** The maintainer selected an extra split after the initial Work Unit 1 implementation measured above budget. The local chain is `feat/ui-payroll-flow` (OpenSpec tracker) → `feat/ui-payroll-flow-status-presentation` (279 changed lines) → `feat/ui-payroll-flow-status-index` (693 changed lines). The maintainer explicitly accepted the 693-line PR1b `size:exception` because further splitting would separate the Index view from the tests and wiring that prove it.

## Work Unit 2 — Processed results and approval confirmation

**Start:** Work Unit 1 is available, and `Procesar` still performs immediate approval.
**Finish:** Results/finalization uses shared status context and explicit accessible confirmation while the existing server-authorized, row-locked `processed` → `approved` path remains authoritative.
**Dependency:** Shared status/workflow seam from Work Unit 1.
**Rollback boundary:** Revert only `app/Livewire/Nomina/Procesar.php`, `resources/views/livewire/nomina/procesar.blade.php`, Procesar-specific shared-dialog adoption, and `VistaPreviaTest`/`AprobarNominaTest` changes; preserve the original row-locked approval transaction and persisted approval metadata.

### RED

- [x] Extend `tests/Feature/Nomina/VistaPreviaTest.php` with failing coverage for eligible `processed`/`approved`/`exported` status context, unchanged rejection of every other status including `cancelled`, current-generation projection, canonical-minute values, filters/pagination, frozen evidence associations, tenant isolation, semantic summaries, responsive results, and distinct no-results versus filtered-no-match states. <!-- sdd-owner: implementation -->
- [x] Replace the obsolete no-confirmation expectation in `tests/Feature/Nomina/AprobarNominaTest.php` with failing tests for opening without mutation, accessible name/description, safe initial focus, cancel/Escape without metadata, focus return, direct `approve` rejection while closed, and scoped busy/disabled duplicate-submit protection. <!-- sdd-owner: implementation -->
- [x] Add failing confirmation protection in `tests/Feature/Nomina/AprobarNominaTest.php` for permission removal after opening, tenant/status recheck, concurrent/stale approval, unchanged row-lock outcome, first-writer actor/time metadata, page-level success/failure feedback, approval only for `processed`, and export only for permitted `approved`/`exported` periods. <!-- sdd-owner: implementation -->

### GREEN

- [x] Update `app/Livewire/Nomina/Procesar.php` with locked confirmation interaction state and request/cancel methods; require an open confirmation before `approve()`, then retain the existing Gate check, tenant-aware `withoutCompanyScope()` lookup, transaction, `lockForUpdate()`, fresh `processed` comparison, metadata write, stale outcome, and refresh behavior. <!-- sdd-owner: implementation -->
- [x] Modernize `resources/views/livewire/nomina/procesar.blade.php` with the shared workflow/status context, `x-ui` alerts/stat cards/forms, wrapping filters, desktop and narrow result presentations, truthful empty states, associated evidence disclosure, locked/finalization risk copy, and status/permission-derived approval and export controls without changing the export GET contract. <!-- sdd-owner: implementation -->
- [x] Implement the approval dialog through `resources/views/components/ui/dialog.blade.php` when created in Work Unit 1, or locally with the same complete contract: Cancel receives initial focus, Escape closes only when idle, focus returns to the trigger, backdrop never confirms, and loading targets only `approve`. <!-- sdd-owner: implementation -->

### TRIANGULATE

- [x] Exercise duplicate confirmation, stale already-approved/exported status, permission revocation, cross-tenant context, empty generation, filter-empty generation, and approved/exported action matrices in `tests/Feature/Nomina/AprobarNominaTest.php` and `tests/Feature/Nomina/VistaPreviaTest.php`, proving no actor/time overwrite and no broadened route eligibility. <!-- sdd-owner: implementation -->

### REFACTOR AND VERIFY

- [x] Remove unreachable `cancelled` presentation from the results view only where safe, deduplicate semantic result markup without recalculation, and run `./vendor/bin/pest tests/Feature/Nomina/VistaPreviaTest.php tests/Feature/Nomina/AprobarNominaTest.php tests/Feature/Ui/DesignSystemComponentsTest.php`; record the exact result plus a manual keyboard confirmation/evidence and narrow/wide viewport check. <!-- sdd-owner: implementation -->

**WU2 evidence:** Focused verification passed on 2026-09-23 after resetting two crashed subagent attempts with maintainer authorization: `./vendor/bin/pest tests/Feature/Nomina/VistaPreviaTest.php tests/Feature/Nomina/AprobarNominaTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` → 30 passed, 138 assertions. `./vendor/bin/pint --test app/Livewire/Nomina/Procesar.php tests/Feature/Nomina/VistaPreviaTest.php tests/Feature/Nomina/AprobarNominaTest.php` passed after formatting. The local harness still emitted the known non-fatal PostgreSQL container warning before SQLite/Pest execution.

## Work Unit 3 — Payroll/overtime progress and overtime review panel

**Start:** Existing child interfaces, polling, counters, and parent event contracts are unchanged.
**Finish:** Payroll progress remains qualitative, overtime batch progress is truthfully determinate, and the overtime panel is accessible/responsive without changing decisions or the frozen parent.
**Dependency:** Reusable dialog may come from Work Unit 1; no dependency on Work Unit 2 behavior.
**Rollback boundary:** Revert only allowed progress/panel component/view changes and their focused tests; parent `Revisar` files, jobs, requesters/recorders, stored batches, and event contracts remain untouched.

### RED

- [x] Extend `tests/Feature/Payroll/PayrollRunProgressTest.php` and focused assertions in `tests/Feature/Nomina/StartPayrollProcessingTest.php` with failing presentation coverage for qualitative `queued`/`processing`/`completed`/`failed` states, an indeterminate active indicator with no percentage/`aria-valuenow`, `wire:poll.3s` only while active, terminal stop/one-time events, delayed warning, recoverability, retry lineage, blockers, sanitized failure/reference feedback, actor/tenant/period scope, permissions, and redirect only for the verified current completed run. <!-- sdd-owner: implementation -->
- [x] Extend the `OvertimeBatchProgress` section of `tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` with failing coverage for `completed = succeeded + failed`, determinate `value/max` only when total is positive, separately labeled total/pending/processing/succeeded/failed counts, zero-total handling, bounded sanitized failures, completion-with-errors copy, actor/tenant/period isolation, three-second active polling, terminal stop, and one-time terminal events. <!-- sdd-owner: implementation -->
- [x] Extend `tests/Feature/Nomina/AttendanceReviewTest.php` and `tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` with failing presentation/accessibility tests for URL normalization, `overtimePage` isolation, grouped candidate facts, no-candidate/no-filter-match feedback, blocked-action explanations, selection reset/all-filtered behavior, exact event names/payloads, stale fingerprints, the 500-target ceiling, required reasons/partial intervals, immutable dialog summaries, focus/Escape/return hooks, responsive sticky actions, and submission-only loading targets. <!-- sdd-owner: implementation -->

### GREEN

- [x] Modernize `resources/views/livewire/nomina/payroll-run-progress.blade.php` and only presentation-derived values in `app/Livewire/Nomina/PayrollRunProgress.php` as necessary, preserving all queries, gates, retry/recovery methods, polling, terminal dispatch, and redirect contracts while providing concise semantic live feedback. <!-- sdd-owner: implementation -->
- [x] Add display-only completed/remaining/percentage derivation to `app/Livewire/Nomina/OvertimeBatchProgress.php` as needed and modernize `resources/views/livewire/nomina/overtime-batch-progress.blade.php`; never count `processing` as completed or label successes alone as processed. <!-- sdd-owner: implementation -->
- [x] Restructure `resources/views/livewire/nomina/overtime-review-panel.blade.php` around existing semantic UI/dialog modules while keeping every public property, filter, paginator, token, fingerprint, request key, validation rule, event direction/name, and payload in `app/Livewire/Nomina/OvertimeReviewPanel.php` unchanged unless presentation-only state is required. <!-- sdd-owner: implementation -->

### TRIANGULATE

- [x] Exercise active-to-terminal transitions, mixed-success batches, zero totals, unavailable foreign batches, blocked individual/batch decisions, invalid URL filters, stale/over-500 selections, partial-decision boundaries, and narrow loading targets in the focused progress/overtime tests, proving terminal announcements occur once and no cross-tenant detail leaks. <!-- sdd-owner: implementation -->

### REFACTOR AND VERIFY

- [x] Remove bespoke status colors and dense duplicated panel markup without modifying either frozen `Revisar` file; run `./vendor/bin/pest tests/Feature/Payroll/PayrollRunProgressTest.php tests/Feature/Nomina/StartPayrollProcessingTest.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php tests/Feature/Nomina/AttendanceReviewTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` and record the exact result plus manual live-region, keyboard-dialog, and narrow/wide viewport checks. <!-- sdd-owner: implementation -->

**WU3 evidence:** Focused verification passed on 2026-09-23: `./vendor/bin/pest tests/Feature/Payroll/PayrollRunProgressTest.php tests/Feature/Nomina/StartPayrollProcessingTest.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php tests/Feature/Nomina/AttendanceReviewTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` → 101 passed, 517 assertions. `./vendor/bin/pint --test app/Livewire/Nomina/OvertimeBatchProgress.php resources/views/livewire/nomina/payroll-run-progress.blade.php resources/views/livewire/nomina/overtime-batch-progress.blade.php resources/views/livewire/nomina/overtime-review-panel.blade.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed. The local harness emitted the known non-fatal PostgreSQL container warning before SQLite/Pest execution.

## Final cross-slice verification

- [x] Run the focused Nómina regression set: `./vendor/bin/pest tests/Unit/Support/Nomina/PayPeriodStatusPresentationTest.php tests/Feature/Nomina/IndexTest.php tests/Feature/Archivos/UploadTest.php tests/Feature/Nomina/VistaPreviaTest.php tests/Feature/Nomina/AprobarNominaTest.php tests/Feature/Nomina/StartPayrollProcessingTest.php tests/Feature/Payroll/PayrollRunProgressTest.php tests/Feature/Nomina/AttendanceReviewTest.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php tests/Feature/Ui/DesignSystemComponentsTest.php`; record exact results and verify the frozen/excluded paths have no diff. <!-- sdd-owner: implementation -->
- [ ] Run `./vendor/bin/pint --test`, `composer test`, and `npm run build`, record exact results, and inspect `git diff --stat` plus `git diff -- app/Livewire/Nomina/Revisar.php resources/views/livewire/nomina/revisar.blade.php openspec/changes/flexible-single-shift-payroll/` to prove scope compliance. <!-- sdd-owner: implementation -->
- [x] Do not run `./vendor/bin/pest -c phpunit.postgresql.xml` for presentation-only changes; run and record it only if implementation unexpectedly changes PostgreSQL-specific locking, concurrency, constraints, or query behavior, and treat such a change as scope/risk requiring review before continuation. <!-- sdd-owner: implementation -->

**Final verification evidence:** Focused Nómina regression passed on 2026-09-23: 188 passed, 974 assertions; frozen `Nomina/Revisar` files and `openspec/changes/flexible-single-shift-payroll/` diff were empty. `npm run build` passed. `./vendor/bin/pint --test` failed on pre-existing files outside this slice, including the explicitly frozen `app/Livewire/Nomina/Revisar.php`; those were not auto-fixed. `composer test` with the default 128MB PHP memory limit exhausted memory in `PhpSpreadsheet` during `ComprobanteDownloadTest`; rerunning with `php -d memory_limit=512M ./vendor/bin/pest` advanced through the suite and failed only environment-dependent production backup tests because `docker` is not installed plus one deploy-script path expectation. PostgreSQL-specific tests were not run because this phase changed presentation only, not PostgreSQL locking, concurrency, constraints, or query behavior.
