# Design: Presentation-only Payroll UI Modernization

## Decision summary

Modernize the five allowed Nómina surfaces behind two presentation seams:

1. `PayPeriodStatusPresentation` maps stored pay-period status strings to display-only metadata.
2. A shared Blade dialog shell supplies consistent dialog semantics and focus lifecycle while each Livewire module retains its own state, validation, authorization, and mutation methods.

No presentation object may answer whether an action is allowed. Existing policies, gates, tenant scope, model methods, route middleware, row locks, polling queries, and parent/child events remain authoritative. The frozen `Nomina\Revisar` class and view are not edited.

## Scope boundaries

### Allowed implementation surfaces

- `app/Livewire/Nomina/Index.php`
- `app/Livewire/Nomina/Procesar.php`
- `app/Livewire/Nomina/PayrollRunProgress.php`
- `app/Livewire/Nomina/OvertimeBatchProgress.php`
- `app/Livewire/Nomina/OvertimeReviewPanel.php`
- Their matching views under `resources/views/livewire/nomina/`
- New presentation support under `app/Support/Nomina/`
- New reusable Blade presentation under `resources/views/components/ui/` or `resources/views/components/nomina/`
- Focused tests for these interfaces

### Frozen or excluded surfaces

- `app/Livewire/Nomina/Revisar.php`
- `resources/views/livewire/nomina/revisar.blade.php`
- `openspec/changes/flexible-single-shift-payroll/`
- Payroll calculations, attendance/overtime rules, queue execution, export transport, audit rules, persistence schema, and status transitions

## Shared status presentation module

Create `app/Support/Nomina/PayPeriodStatusPresentation.php` as one immutable, final value module. Its small interface is:

```php
PayPeriodStatusPresentation::for(string $status): PayPeriodStatusPresentation
PayPeriodStatusPresentation::phases(): array
```

An instance exposes only:

- original `status`
- `known` flag
- exact Spanish `label`
- `badgeVariant` accepted by `x-ui.badge`
- `phaseKey` and `phaseIndex` for orientation
- concise explanatory `copy`

It must not expose `canUpload`, `canReview`, `canApprove`, `canExport`, transition names, route names, policy results, tenant identifiers, or mutation methods. Deleting this module would force label/tone/phase/copy duplication back into callers, while deleting it cannot affect authorization or payroll behavior.

### Canonical metadata

| Stored status | Label | Badge | Visual phase | Explanatory copy |
| --- | --- | --- | --- | --- |
| `draft` | Borrador | `neutral` | 1 · Período | El período fue creado y puede recibir archivos de asistencia. |
| `uploaded` | Archivo cargado | `brand` | 2 · Carga | Hay un archivo cargado y se permite cargar otro; esto no confirma la validación. |
| `validating` | Validando | `warning` | 3 · Revisión | La asistencia está en revisión y deben resolverse los bloqueos visibles. |
| `validation_failed` | Validación con errores | `danger` | 2 · Carga | La validación falló; corrija el archivo y vuelva a cargarlo. |
| `ready` | Listo | `success` | 3 · Revisión | La revisión está lista y el procesamiento puede solicitarse si no hay bloqueos. |
| `processing` | Procesando | `brand` | 4 · Proceso | El cálculo está activo y la asistencia no puede editarse. |
| `processed` | Procesado | `success` | 5 · Aprobación y exportación | Los resultados actuales están congelados y disponibles para revisión y aprobación. |
| `approved` | Aprobado | `success` | 5 · Aprobación y exportación | La nómina está aprobada, bloqueada y disponible para exportar. |
| `exported` | Exportado | `neutral` | 5 · Aprobación y exportación | La nómina fue exportada, permanece bloqueada y puede exportarse nuevamente. |
| `cancelled` | Cancelado | `danger` | 5 · Aprobación y exportación | El período está cancelado y no admite edición. |

Phase 5 is a finalization display bucket, not evidence that every preceding transition occurred. In particular, `cancelled` renders phase 5 with terminal/danger treatment and no completed-step claim.

Unknown values return `known=false`, label `Estado desconocido`, a neutral badge, no active phase, and copy that the stored state is not recognized. Unknown values are never normalized to `draft`, “pending,” or another known state.

### Workflow stepper

Create `resources/views/components/nomina/payroll-workflow.blade.php` with two inputs: the phase list and an optional `PayPeriodStatusPresentation`. It renders the five fixed phases:

1. Período
2. Carga
3. Revisión
4. Proceso
5. Aprobación y exportación

The stepper is descriptive only. Completed/current styling derives from `phaseIndex`, while accessible text names the current stored status and phase. It emits no links and makes no action decisions. On Index it may render as general orientation when there is no selected period; each period row/card carries its own status and next-action copy. On Procesar it renders with the current period presentation.

## Shared dialog shell

Create `resources/views/components/ui/dialog.blade.php` only if its interface can serve approval, deletion, and overtime dialogs without carrying domain state. It owns:

- backdrop and panel structure
- `role="dialog"` and `aria-modal="true"`
- required `aria-labelledby` and `aria-describedby` IDs
- initial focus on an explicitly marked safe control
- Escape invoking the supplied close action when no submission is busy
- focus return to the element that opened the dialog
- keyboard focus containment implemented locally, without assuming an uninstalled Alpine focus plugin
- header, body, and action slots

The caller owns all text, fields, validation errors, button variants, Livewire targets, and close/submit methods. Backdrop clicks do not confirm or silently dismiss consequential actions. The dialog is a presentation module, not a source of authorization or confirmation truth.

If this reusable shell cannot be introduced within the selected review slice, the same tested accessibility contract may be implemented locally first; do not create a shallow wrapper that only renames a `<div>`.

## Data flow and authorization separation

```text
PayPeriod.status ──> PayPeriodStatusPresentation ──> badge / phase / copy
       │
       ├── PayPeriod::canUploadFiles() + Gate(files.upload) ──> upload CTA
       ├── Gate(marks.manage) ────────────────────────────────> review CTA
       ├── PayPeriodPolicy::delete ───────────────────────────> delete CTA
       ├── Gate(payroll.approve) + status under row lock ─────> approval
       └── Gate(payroll.export) + status ─────────────────────> export CTA
```

Index and Procesar prepare status presentation objects in `render()` and pass them to Blade. Blade receives action booleans separately. Status metadata is never accepted from the browser and is never consulted by a mutation.

Every direct Livewire action keeps or adds its own authorization check. Route middleware remains unchanged. Queries continue to use current-company and explicit company/period/actor predicates. Hiding a CTA is usability only and never replaces server enforcement.

## Surface integration

### Index

`Index::render()` supplies a presentation object keyed by period ID plus the fixed phase list. It also supplies permission-derived visibility separately:

- create: existing `PayPeriodPolicy::create`
- upload: `PayPeriod::canUploadFiles()` **and** `files.upload`
- review: `marks.manage`
- delete: existing `PayPeriodPolicy::delete`

Do not derive any of these from `phaseIndex` or badge tone. Preserve route parameters and existing navigation behavior; adding `wire:navigate` is allowed only where the current route behavior already supports it and tests retain the existing destination.

The view uses `x-ui.page-header`, cards, badges, alerts, empty state, form controls, buttons, and semantic token families. Desktop keeps a scan-friendly table. Below the desktop breakpoint, render stacked period cards rather than requiring an unlabeled wide table. Do not duplicate interactive controls simultaneously in an accessibility-visible desktop and mobile tree; use responsive visibility consistently.

The no-company state remains tenant-safe and contains no period data or create control. The no-period state includes a create action only when the existing create policy allows it.

Deletion retains the same period ID, required reason, 500-character limit, soft deletion, associated-file deletion, and audit path. The modernized dialog adds an accessible name/description, initial focus on the reason field, Escape cancellation, focus return, semantic danger styling, and busy-disabled confirmation. It does not change deletion eligibility.

### Procesar

`Procesar::render()` supplies the current status presentation and phase list alongside the existing projection, summary, evidence, `canApprove`, and `canExport` values. Mount eligibility remains exactly `processed`, `approved`, or `exported`; do not make the existing `cancelled` branches reachable.

The view becomes “Revisión y finalización de nómina” and includes:

- period name/date/status context and the five-phase orientation
- session success/warning/error feedback through `x-ui.alert`
- semantic stat cards based on the current projection
- wrapping filters with explicit labels
- a desktop table and small-screen stacked result summaries, each preserving canonical-minute values and evidence access
- one no-results state when the generation contains no rows and a distinct no-match state when active filters return no rows
- an accessible evidence region/disclosure that displays the same frozen snapshot without recalculation
- locked/finalization risk copy for approved/exported states
- approval and export actions derived only from existing gate/status methods

Export remains the existing state-changing GET and existing route. Copy may explain its consequence; this design does not alter transport or transition behavior.

## Approval confirmation state model

Add a `#[Locked] public bool $showApprovalConfirmation = false` property to `Procesar`. The locked flag is interaction state, not authorization.

```text
closed
  └─ requestApprovalConfirmation()
       ├─ unauthorized or not freshly processed -> remain closed
       └─ authorized and processed -> open

open
  ├─ cancelApprovalConfirmation() / Escape -> closed, no mutation
  └─ approve() -> submitting (client busy state)
                    ├─ permission denied -> existing 403 behavior
                    ├─ fresh locked status != processed -> refresh state, close, no mutation
                    └─ fresh locked status == processed -> existing approved update,
                       actor/time metadata, refresh, close, success feedback
```

The page-level approval button calls `requestApprovalConfirmation`; only the dialog confirmation calls the existing `approve` method. `approve` first requires the locked confirmation state, then retains `Gate::authorize('payroll.approve')` and the existing transaction with `withoutCompanyScope()`, `lockForUpdate()`, and the `processed` comparison. Thus modal state cannot substitute for permission, tenancy, or status checks.

Cancellation changes no model state. `wire:loading.attr="disabled"`, a scoped loading target, and the locked row make duplicate submissions harmless; the first successful request records the existing `approved_at` and `approved_by`, while later/stale requests cannot overwrite them. A stale race refreshes the local period and reports that approval was not applied without claiming success.

Initial focus goes to Cancel, not the consequential confirm action. Escape closes only while not submitting, and focus returns to the approval trigger.

### PayrollRunProgress

Keep `runId`, status lookup, tenant/period predicates, `payroll.process` gate, delayed threshold, recovery, retry lineage, one-time terminal event, and three-second active polling unchanged.

Presentation rules:

- `queued` and `processing` use an indeterminate visual indicator with no `value`, percentage, elapsed estimate, or implied completion fraction.
- `completed` and `failed` are named terminal states and remove `wire:poll`.
- delayed queue, lost-lease recovery, blocker failure, generic sanitized failure, retry, and reference ID remain visible and actionable as today.
- one polite live region announces state changes; danger failures may use an assertive alert once, but animation itself is hidden from assistive technology.

The view must contain no percent sign or ARIA percentage value for payroll-run progress.

### OvertimeBatchProgress

Keep the existing actor-, tenant-, and period-scoped lookup, count queries, five-error limit, terminal classification, one-time event, and three-second active polling.

Compute display-only completion as:

```text
completed = succeeded + failed
remaining = max(0, total - completed)
percentage = total > 0 ? clamp(0, 100, completed / total * 100) : unavailable
```

Use a native `<progress value="completed" max="total">` or equivalent determinate ARIA values only when `total > 0`. This percentage is valid because it derives from real terminal item counts. `processing` is not counted as completed. Copy separately reports total, completed, successful, failed, processing, and pending counts; it must never label `succeeded` alone as “procesados.” For zero total, show named loading/empty status without a fabricated 0% claim.

Failure details remain sanitized values already returned by the existing query. Terminal batches stop polling and announce once.

### OvertimeReviewPanel

Keep every public property, URL filter, paginator name, candidate token, selection fingerprint, request key, payload field, event name, and parent event direction unchanged. The frozen parent receives exactly the current event contracts.

Restructure only the view and accessible dialog presentation:

- semantic card and form modules for filters
- grouped employee disclosures with the existing candidate facts
- visible labels for all inputs, including partial interval endpoints and required reasons
- blocker text adjacent to disabled individual and batch actions when `isBlocked`
- separate no-candidate and no-filter-match states where the existing data can distinguish them; otherwise use one truthful filtered-empty state
- sticky bulk actions that wrap at small widths and retain selection count
- dialog names/descriptions, immutable candidate summary, required reason, partial interval constraints, initial focus, Escape, focus return, and scoped busy state

Do not alter decision validation, partial eligibility, selection reset, 500-item ceiling, stale confirmation hash, or dispatch payloads. Loading targets stay narrow so checkbox and filter updates do not show decision-submission loading feedback.

## Design-system and responsive rules

- Prefer existing semantic colors: `brand`, `surface`, `border`, `text`, `success`, `warning`, and `danger`.
- Prefer existing `x-ui` modules over local copies when their interfaces fit.
- Keep minimum primary control height at 44px and visible focus rings.
- Do not communicate status, disabled state, failure, or completion by color alone.
- Header actions and filters stack on narrow screens and wrap without obscuring the primary action.
- Desktop tables keep headers and exact values; mobile cards retain the same essential labels, values, and actions.
- Horizontal scrolling, where retained for detailed evidence, receives an accessible label and visible hint.
- Live regions are concise, atomic where needed, and inactive after terminal states.

## Exact contracts preserved

The change does not add, rename, collapse, infer, or reorder stored states. These transitions remain the only documented transition model:

- creation → `draft`
- review save/reopen → `validating`
- readiness/start checks → `ready`
- processing → `processing` → `processed`
- approval → `approved`
- first approved export → `exported`
- processed reopening → `validating`, preserving prior generations
- audited soft deletion remains distinct from `cancelled`

Upload remains limited to `draft`, `uploaded`, and `validation_failed`. Approval remains limited to `processed`. Export remains limited to `approved` and `exported`. Payroll-run and overtime-batch statuses remain separate domains and never pass through `PayPeriodStatusPresentation`.

## Planned file changes

### Create

- `app/Support/Nomina/PayPeriodStatusPresentation.php`
- `resources/views/components/nomina/payroll-workflow.blade.php`
- `resources/views/components/ui/dialog.blade.php` if implemented as the deep shared dialog seam
- focused presentation tests if separation from existing feature files improves review locality

### Modify

- `app/Livewire/Nomina/Index.php`
- `app/Livewire/Nomina/Procesar.php`
- optionally presentation-only derived values in `PayrollRunProgress.php` and `OvertimeBatchProgress.php`; no query/event changes
- `resources/views/livewire/nomina/index.blade.php`
- `resources/views/livewire/nomina/procesar.blade.php`
- `resources/views/livewire/nomina/payroll-run-progress.blade.php`
- `resources/views/livewire/nomina/overtime-batch-progress.blade.php`
- `resources/views/livewire/nomina/overtime-review-panel.blade.php`
- `tests/Feature/Nomina/IndexTest.php`
- `tests/Feature/Nomina/VistaPreviaTest.php`
- `tests/Feature/Nomina/AprobarNominaTest.php`
- `tests/Feature/Payroll/PayrollRunProgressTest.php`
- `tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php`
- focused sections of `tests/Feature/Nomina/AttendanceReviewTest.php`
- `tests/Feature/Ui/DesignSystemComponentsTest.php` when the dialog/workflow modules are shared

### Explicitly unchanged

- both frozen `Nomina/Revisar` files
- routes and export controller
- models, migrations, payroll services, queue jobs, and overtime recorders/requesters
- all files under `openspec/changes/flexible-single-shift-payroll/`

## Test strategy

Implementation follows focused RED → GREEN → REFACTOR cycles through public Livewire interfaces and rendered HTML.

### Shared presentation

- table-driven coverage for all ten exact statuses: label, badge, phase, and copy
- unknown status remains explicit and has no active phase
- metadata object exposes no action/authorization decisions
- workflow component identifies the current phase without creating links or transition controls

### Index

- retain creation, tenant-derived company, validation, overlap/slug guards, upload redirect, deletion reason, associated-file soft deletion, and audit assertions
- upload CTA requires both `canUploadFiles()` and `files.upload`; assert the exact upload matrix
- review CTA requires `marks.manage`
- direct unauthorized Livewire/route requests remain forbidden even when CTAs are hidden
- no-company and cross-tenant data remain isolated
- deletion dialog role, name, description, labeled reason, initial-focus hook, Escape close hook, focus-return hook, and busy state
- responsive table/card semantics and useful empty state

### Procesar and approval

- retain mount eligibility, current-generation projection, canonical-minute rendering, filters, pagination, evidence, and tenant isolation
- replace the current “without confirmation” test with open/cancel/confirm tests
- assert cancel and Escape leave status `processed`
- assert direct `approve` while confirmation is closed does not mutate
- assert confirmation rechecks permission and fresh status
- retain row-lock race test, actor/time metadata, locked state, and export availability only after success
- duplicate confirmation cannot overwrite approval metadata
- render session feedback, unfiltered empty state, filtered no-match state, accessible evidence, and responsive result presentation

### Progress

- payroll: preserve qualitative names, no percent/`aria-valuenow`, active `wire:poll.3s`, terminal stop, delayed warning, recovery, retry lineage, sanitized failure, one-time event, and verified redirect contract
- overtime batch: assert completed equals success plus failure, determinate `value/max`, separate count labels, no success-only “processed” wording, terminal stop, failure details, one-time event, and actor/tenant/period isolation

### Overtime review

- retain URL normalization, `overtimePage`, grouped rows, candidate facts, exact decision/batch payloads, selection reset, all-filtered behavior, fingerprint rejection, 500-item limit, and blocked mutations
- assert blocker explanation is textually associated with disabled actions
- assert dialog names/descriptions, field labels, initial focus hooks, Escape/focus return, validation errors, and narrow loading targets
- avoid full-page snapshots; assert semantic markers and behavior at the existing interface

## Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Status metadata becomes a second state machine | Keep only label/tone/phase/copy and prohibit action booleans or transitions |
| A hidden CTA is mistaken for authorization | Retain route middleware and reauthorize every direct Livewire mutation |
| Confirmation can be forged or becomes stale | Lock modal state, require the open state, then recheck Gate and status under the existing row lock |
| Responsive duplication creates duplicate controls | Render one accessibility-visible control set per breakpoint and test unique action targets |
| Payroll progress implies false precision | Use named states and indeterminate visuals only; assert absence of percentage semantics |
| Batch progress mislabels failures | Define completed as `succeeded + failed` and display all counters separately |
| Dialog polish regresses keyboard access | Centralize and test name, description, initial focus, Escape, containment, focus return, and busy behavior |
| Frozen parent contracts drift | Do not edit either Revisar file; retain all child event names and payloads |
| Related overtime change leaks into this scope | Do not touch or reinterpret `flexible-single-shift-payroll` artifacts or policy |
| Review scope exceeds 400 lines | Stop before implementation delivery planning and request the required `ask-on-risk` decision |

## Rollout and rollback

There are no migrations, data backfills, feature flags, or persisted metadata changes. Deploy presentation slices only after their focused suites pass. Existing server authorization and transition paths stay active throughout rollout.

Rollback is code-only:

1. Revert the affected view slice and its presentation wiring.
2. Revert the shared status/workflow/dialog modules once no caller uses them.
3. For Procesar, revert confirmation state and restore the prior trigger while leaving the row-locked approval transaction untouched.

No payroll results, approval metadata, audit records, queue records, or exports need repair after rollback.

## Delivery gate

The expected implementation exceeds the 400 changed-line review budget. With `ask-on-risk`, no implementation or delivery plan may proceed until a human selects reduced scope, an explicit cohesive chain strategy, or an explicit `size:exception`. Candidate slices remain:

1. shared status presentation and Index
2. Procesar and approval confirmation
3. payroll/overtime progress and OvertimeReviewPanel

These candidates are not an authorized chain and do not imply a base strategy.
