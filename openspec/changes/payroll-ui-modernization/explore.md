# Exploration: Payroll UI modernization

## Intent and boundaries

Modernize how the general Nómina workflow communicates state, progress, next actions, blockers, risk, and empty results without changing payroll behavior. The implementation scope should center on:

- `app/Livewire/Nomina/Index.php`
- `app/Livewire/Nomina/Procesar.php`
- `app/Livewire/Nomina/PayrollRunProgress.php`
- `app/Livewire/Nomina/OvertimeBatchProgress.php`
- `app/Livewire/Nomina/OvertimeReviewPanel.php`
- Their matching Blade views under `resources/views/livewire/nomina/`

`app/Livewire/Nomina/Revisar.php` and `resources/views/livewire/nomina/revisar.blade.php` are explicit non-goals. The overtime and progress modules embedded by that screen may be modernized through their own interfaces, but the parent review module must not be refactored or given new responsibilities.

## Current-state workflow map

1. **Period selection and creation — `Nomina\Index`**
   - Lists tenant-scoped periods, paginated newest first.
   - Creates a `draft` period after policy authorization, tenant-context resolution, validation, slug collision checks, and overlap protection.
   - Redirects successful creation directly to `archivos.upload` with the new period selected.
   - Soft-deletes a period and all associated uploaded files after a required reason, recording audit entries where the audit table exists.
   - Shows upload only when `PayPeriod::canUploadFiles()` is true, but always shows a review link and does not gate the rendered upload/review links by the route-specific permissions.

2. **Upload and validation — existing adjacent workflow**
   - `Archivos\Upload` accepts only `draft`, `uploaded`, or `validation_failed` periods, rechecks the active company and status on submit, validates the file synchronously, and redirects to the uploaded-file result.
   - Upload is already presented as a three-part guided page and clearly says that validation does not process payroll.
   - No application producer was found that changes a pay period to `uploaded` or `validation_failed`; these states are nevertheless stored/displayed, explicitly uploadable, dashboard-visible, and covered by upload tests. They must remain first-class presentation states rather than being removed or silently reinterpreted.

3. **Attendance review — existing `Nomina\Revisar` parent, out of refactor scope**
   - Moves reviewable periods to `validating`, resolves blockers, can advance to `ready`, and starts a payroll run.
   - Owns the parent event handlers for overtime decisions/batches and payroll-run terminal events.
   - A completed verified run redirects to `nomina.procesar`.
   - Because this parent is frozen for this change, modernization must use the current event contracts and reactive inputs unchanged.

4. **Overtime review — `OvertimeReviewPanel`**
   - Is an independently testable child module with URL-backed filters, a dedicated paginator, grouped employee disclosures, individual full/partial decisions, page/all-filtered selection, and audited batch confirmation.
   - Blocks mutation through the reactive `isBlocked` input while retaining the current visual content.
   - Dispatches intents to the parent rather than recording decisions itself. Selection fingerprints and a 500-item ceiling protect stale or over-broad batch requests.

5. **Background progress**
   - `PayrollRunProgress` polls every three seconds for queued/processing runs, stops at completed/failed, reports delayed queues, exposes explicit abandoned-run recovery, and permits retry from failed runs.
   - It deliberately reports named states rather than a fabricated percentage; this is protected by a test.
   - `OvertimeBatchProgress` independently polls actor-, tenant-, and period-scoped batches, reports counts and up to five failures, stops when terminal, and notifies the parent once.

6. **Processed-result review and finalization — `Nomina\Procesar`**
   - Is accessible only for `processed`, `approved`, or `exported`; other states redirect to review with a warning.
   - Reads paginated frozen payroll results and summaries through `PayrollResultsReviewProjection`.
   - Supports employee-id and worked/absence filters and exposes raw frozen evidence on demand.
   - Changes `processed` to `approved` under a row lock and records actor/time metadata. Approval currently happens immediately, without a confirmation dialog.
   - Shows export only for `approved`/`exported`; the export controller changes `approved` to `exported` under a lock.
   - `cancelled` rendering branches exist in this module, but normal mounting rejects `cancelled`, making those branches unreachable through the standard route.

## Relevant modules and views

| Area | Server-side interface | View | Current presentation |
| --- | --- | --- | --- |
| Period overview | `Index::render/openCreateForm/store/openDeleteConfirmation/deletePeriod` | `nomina/index.blade.php` | Modern header and inline form, three-card step indicator, desktop-width table, inline status mapping, destructive modal |
| Result review | `Procesar::render/showEvidence/approve/canApprove/canExport` | `nomina/procesar.blade.php` | Legacy gray/blue styling, summary cards, dense horizontal table, immediate approval, raw JSON evidence |
| Payroll run | `PayrollRunProgress::poll/retry/recover` | `nomina/payroll-run-progress.blade.php` | Text alert with named state, delayed/recovery/failure messages, no quantitative progress |
| Overtime batch | `OvertimeBatchProgress::poll` | `nomina/overtime-batch-progress.blade.php` | Text counts and failure list, no progress bar, raw batch status label |
| Overtime decisions | `OvertimeReviewPanel` filter/selection/modal/event interface | `nomina/overtime-review-panel.blade.php` | Responsive grouped cards and sticky bulk action bar, but dense markup and bespoke controls/dialogs |

The allowed child modules already provide useful seams: presentation can change while authorization, tenant scoping, stale-request protection, transitions, and event payloads stay behind their existing interfaces.

## Design-system patterns found

The repository has a semantic Tailwind theme in `resources/css/app.css`:

- `brand`, `surface`, `border`, and `text`
- `success`, `warning`, and `danger`, including strong/subtle variants

Reusable Blade modules exist for:

- `x-ui.page-header`
- `x-ui.card`
- `x-ui.stat-card`
- `x-ui.badge`
- `x-ui.alert`
- `x-ui.empty-state`
- `x-ui.button`
- `x-ui.input`, `x-ui.select`, and `x-ui.textarea`
- `x-ui.loading-button` and `x-ui.loading-overlay`

These modules establish rounded cards, semantic tones, 44px-or-larger primary controls, focus-visible rings, and accessible alert/live-region behavior. `DesignSystemComponentsTest` protects their core markup and semantic token families.

Current Nómina screens are inconsistent with that system:

- `Index` and the upload page use polished but mostly literal `slate`/`indigo` Tailwind classes instead of semantic modules.
- `Procesar` uses older `gray`, basic `rounded shadow`, and ad hoc green/blue/yellow/red styling.
- Both progress views and the overtime panel use bespoke status colors and controls.
- Status label/tone mapping is duplicated across Nómina and dashboard views. A small shared presentation module for pay-period label, tone, workflow phase, and explanatory copy would improve locality, provided it remains presentation-only and does not decide authorization or transitions.
- Existing dialogs lack a shared dialog module. Current modals have inconsistent `role="dialog"`, accessible naming, labels, focus return, Escape handling, and destructive-risk copy.

## State semantics that must remain exact

| Pay-period status | Existing meaning and allowed behavior | Presentation implication |
| --- | --- | --- |
| `draft` | Newly created; uploadable; review/start logic can still evaluate it | Preparation, with upload as the likely next action |
| `uploaded` | Uploadable stored state; no producer found in current application code | File present / further upload allowed; do not infer validation completion beyond the stored state |
| `validating` | Mutable attendance-review state; non-uploadable | Review in progress; surface blockers and review CTA |
| `validation_failed` | Uploadable error state; no producer found in current application code | Error/recovery state; upload correction is available |
| `ready` | Review has passed or was explicitly advanced; non-uploadable; payroll run may be requested | Ready to process, not yet processed |
| `processing` | Payroll processor is running; attendance is locked | Active, non-editable state; no duplicate processing CTA |
| `processed` | Frozen current result generation exists; attendance locked; may be approved or explicitly reopened | Results-review and approval stage |
| `approved` | Approval actor/time recorded; locked; export allowed | Finalized for export, with immutable warning |
| `exported` | Export has occurred; locked; export remains available | Completed/exported state, not a new calculation state |
| `cancelled` | Locked terminal state | Terminal danger/neutral communication; no editing CTA |

Confirmed transitions include:

- creation → `draft`
- review save/reopen → `validating`
- readiness/start checks → `ready`
- payroll processing → `processing` → `processed`
- approval → `approved`
- first approved export → `exported`
- processed reopening → `validating` while preserving prior result generations
- audited soft deletion is separate from `cancelled`

The proposal must not add, collapse, reorder, or rename statuses. It must not turn visual workflow phases into new persisted states. Payroll-run states (`queued`, `processing`, `completed`, `failed`) and overtime-batch/item states are separate from pay-period status and must stay separate in copy and tests.

## UX findings and opportunities

### Stepper and orientation

- The `Index` stepper has only three generic steps and does not represent processing, approval, or export.
- A presentation-only five-phase model—period, upload, review, process, approve/export—can map all ten statuses without changing persistence.
- Because `Revisar` is frozen, do not require a parent-review rewrite to ship the first modernization. Reuse the mapping on `Index` and `Procesar`; adoption by the parent review page is a later change.

### Status badges and CTAs

- Centralize exact Spanish labels and semantic tones; unknown states should remain visibly unknown rather than being called merely “pending.”
- Derive explanatory copy from status, but continue deriving action availability from existing model methods, policies, gates, and route constraints.
- Hide or disable links the user cannot execute. In particular, protect upload visibility with `files.upload` and review visibility with `marks.manage`, while preserving direct-route authorization.
- Avoid changing deletion eligibility during a visual modernization. The current policy permits managed, active-company periods to be deleted regardless of status; changing that is domain/policy work.

### Progress and feedback

- Keep payroll progress qualitative: queued, processing, completed, failed. Do not invent a percentage.
- A visual indeterminate indicator and concise “what happens next” copy can improve queued/processing communication.
- Batch progress has real counts and can show a determinate bar. Its current “N de total procesados” uses succeeded count only, while failures are separately terminal; copy should distinguish completed items, successes, and failures without altering counters.
- Preserve delayed-worker, lease-recovery, retry, terminal-event, and sanitized failure behavior.
- `Procesar` does not render its approval flash itself and has no local empty-result state. Add explicit page-level feedback and a useful no-results/no-filter-matches state.

### Confirm dialogs and risk messages

- Period deletion already requires a reason and audit warning; retain those guarantees while improving accessible naming, focus behavior, accents, and consequence summary.
- Overtime individual/batch dialogs must retain required reasons, immutable candidate information, partial interval constraints, selection fingerprints, and the 500-target cap.
- Approval is a consequential transition and currently has no confirmation. A confirmation step can improve communication without changing the `processed` → `approved` transition, but it intentionally replaces the existing “without a confirmation modal” test and must be specified before implementation.
- Export is a GET that causes the `approved` → `exported` transition. Changing its HTTP behavior is outside this UI proposal; copy can still communicate that export finalizes the visible status.

### Responsive and accessible behavior

- `Index` and `Procesar` depend on horizontally scrolling tables. Keep a table at larger widths, but provide scan-friendly stacked metadata/actions at small widths or a clearly signposted scroll region.
- `Procesar` header actions and filters need wrapping/stacking on small screens.
- Overtime cards are already responsive, but the dense one-line Blade structure makes accessibility and review difficult. Refactor markup only, not event contracts.
- Dialogs need `aria-labelledby`/`aria-describedby`, labeled form fields, initial focus, Escape close, focus return, and busy/disabled semantics.
- Disabled overtime actions need adjacent blocker explanation, not color/opacity alone.
- Preserve live regions and stop polling after terminal states to avoid repeated announcements.

## Strict-TDD protection seams

Use focused RED → GREEN → REFACTOR cycles through the existing Livewire interfaces and rendered HTML.

1. **Period creation and deletion — `tests/Feature/Nomina/IndexTest.php`**
   - Preserve `draft` creation, tenant-derived company, overlap/slug validation, redirect to upload, required deletion reason, associated-file soft deletion, audit metadata, and no client-controlled company/status fields.
   - Add presentation assertions for status copy, next-action copy, modal accessibility, and unchanged action dispatch.

2. **Permissions and tenant context**
   - Preserve policy checks on every direct Livewire action and route.
   - Add visibility tests for create, upload, review, delete, approve, export, processing retry/recovery, and overtime decisions.
   - Retain active-company isolation for company admins and super admins, including forged cross-tenant run/batch IDs and the no-company guided state.

3. **Upload availability — `PayPeriod::UPLOADABLE_STATUSES`, `IndexTest`, and `tests/Feature/Archivos/UploadTest.php`**
   - Prove only `draft`, `uploaded`, and `validation_failed` expose/accept upload.
   - Prove `validating`, `ready`, `processing`, `processed`, `approved`, `exported`, and `cancelled` do not gain upload access through presentation changes.

4. **Review links and workflow orientation**
   - Preserve route parameters and `wire:navigate` behavior where present.
   - Prove each status maps to the intended label/phase while links remain permission-correct.
   - Do not require edits to either `Revisar` file for these tests.

5. **Processing and failure — `tests/Feature/Payroll/PayrollRunProgressTest.php` and `StartPayrollProcessingTest.php`**
   - Preserve qualitative progress with no percentage, three-second active polling, terminal stop, delayed queue warning, recovery, retry lineage, one-time events, blocker failures, sanitized errors, and redirect only for a verified current run.

6. **Overtime progress and decisions — `AttendanceReviewTest`, `OvertimeDecisionBatchRequesterTest`, and UI feedback tests**
   - Preserve URL filter normalization, paginator isolation, grouped rows, exact candidate data, disabled blocked actions, selection reset, confirmation fingerprint, 500-item bound, actor/tenant scoping, count semantics, failure details, and terminal events.
   - Keep loading targets scoped so selection actions do not trigger full decision-loading feedback.

7. **Processed results and final actions — `VistaPreviaTest` and `AprobarNominaTest`**
   - Preserve current-generation projection, canonical-minute display, filters, evidence, locked-state communication, row-locked approval, race handling, approval/export permissions, and export availability only after approval.
   - Add empty-result/filter-empty states and approval-confirmation tests only after the proposal explicitly adopts confirmation.

8. **Design-system/accessibility regression — `DesignSystemComponentsTest` plus focused Nómina view tests**
   - Prefer semantic module assertions over brittle full snapshots.
   - Assert dialog roles/names, live regions, disabled semantics, focus hooks, responsive classes, and use of semantic status variants.

## Risks and non-goals

### Risks

- **Status drift:** centralizing visual metadata could accidentally become a second transition engine. Keep the module presentation-only and leave all mutations in current methods/services.
- **Dead or misleading CTAs:** status alone is insufficient; action visibility also depends on permission, tenant context, active runs, and model methods.
- **Unreachable cancelled result UI:** styling it without resolving `Procesar::mount()` would create untested dead presentation. Do not broaden route eligibility in this change.
- **Approval behavior change:** adding confirmation is desirable UX but is an observable interaction change and conflicts with an existing test. It requires explicit proposal acceptance.
- **Accessibility regressions in bespoke modals:** visual restyling without focus and labeling tests can make current dialogs worse.
- **Progress misinformation:** payroll runs have no trustworthy percentage; only overtime batches support determinate counts.
- **Review-budget risk:** modernizing five views, introducing shared presentation pieces, and adding strict-TDD coverage is likely to exceed 400 changed lines. Under `ask-on-risk`, implementation must pause for a delivery decision rather than infer a chain strategy or size exception.
- **Concurrent related change:** `flexible-single-shift-payroll` affects overtime language and result fields. This change must not modify that change directory or redefine its domain policy.

### Non-goals

- No refactor or edit of either `Revisar` file.
- No payroll calculation, attendance policy, overtime decision, persistence, queue, export, or audit behavior changes.
- No new persisted statuses or transition rules.
- No change to upload parsing/validation or accepted file contract.
- No deletion-policy redesign, cancellation workflow, or processed-period reopening redesign.
- No conversion of export from state-changing GET in this UI-only scope.
- No modification of `openspec/changes/flexible-single-shift-payroll/`.

## Recommended next proposal scope

Propose a presentation-only modernization with these bounded outcomes:

1. Introduce one shared pay-period presentation module that maps the ten exact statuses to Spanish label, semantic badge tone, workflow phase, and concise explanatory copy. It must expose no mutation or authorization decisions.
2. Modernize `Index` with the shared mapping, a five-phase explanatory workflow, permission-correct CTAs, semantic UI modules, improved empty state, responsive period presentation, and an accessible deletion confirmation while retaining all existing actions and validations.
3. Modernize `Procesar` as “result review and finalization”: period/status context, semantic summary cards, responsive filters/results, empty states, clearer locked/risk messages, accessible evidence disclosure, and status-aware approval/export actions.
4. Modernize `PayrollRunProgress` without percentages and `OvertimeBatchProgress` with truthful determinate counts, preserving polling/event/security contracts.
5. Restructure `OvertimeReviewPanel` markup around the existing filters, grouped results, decision/batch intents, sticky selection bar, and modal state; improve blocker explanations, empty states, responsive controls, and accessible dialogs without changing payloads.
6. Specify whether approval confirmation is included. If included, replace the existing no-confirmation expectation with tests proving confirmation, cancellation, permission recheck, race safety, and the same final transition.
7. Keep strict TDD focused on the seams above and avoid touching the frozen parent review module.

This likely needs multiple review units because the complete visual and test scope is above the 400-line budget. The proposal should identify cohesive slices (shared status presentation + index; processed results; progress + overtime panel), but the chain strategy must remain undecided until the required `ask-on-risk` delivery gate is answered.

## Ready for proposal

Yes. The current interfaces are sufficient for a presentation-only change. The proposal should make the exact status mapping, permission-correct CTA matrix, accessible confirmation behavior, and no-fabricated-progress rule explicit, while preserving all tenant, authorization, transition, polling, and event invariants.
