# Proposal: Modernize the Payroll Workflow UI

## Intent

Modernize the general Nómina workflow so payroll operators can quickly understand the current stage, blockers, risk, progress, and next permitted action without changing payroll behavior. The outcome should be a coherent, accessible, responsive experience from period creation through export, built from the existing design system and protected by strict TDD at current authorization, tenant, transition, locking, polling, event, and audit seams.

## Business Outcome

- Reduce uncertainty about where a pay period is in the workflow and what happens next.
- Make permitted actions and blocking conditions visible at the moment an operator needs them.
- Give consequential actions—especially approval—clear, accessible risk communication.
- Make progress and failure feedback truthful and actionable without inventing precision.
- Align Nómina screens with the repository design system while preserving existing domain behavior.

## Scope

### In Scope

1. Introduce a presentation-only mapping for every existing pay-period status to its exact Spanish label, semantic badge tone, five-phase workflow position, and concise explanatory copy.
2. Modernize the period overview with a five-phase stepper, permission-correct CTAs, status badges, blockers, feedback, improved empty states, responsive period presentation, and an accessible deletion dialog.
3. Modernize processed-result review and finalization with clearer period context, semantic summaries, responsive filters/results, useful empty and no-match states, evidence disclosure, locked/risk messages, and status-aware approval/export actions.
4. Add an explicit accessible confirmation step before `processed` → `approved`. Confirmation must describe the consequence, support confirm and cancel, provide accessible naming and description, manage initial focus/Escape/focus return, expose busy/disabled state, and prevent duplicate submission. Final confirmation must call the existing approval path so permission checks, row locking, race behavior, and approval actor/time metadata remain unchanged and are rechecked server-side.
5. Improve payroll-run progress with qualitative queued/processing/completed/failed presentation, an indeterminate active indicator, delayed-worker and recovery guidance, retry feedback, and terminal announcements—without a fabricated percentage.
6. Improve overtime-batch progress with truthful determinate counts, distinguishing completed items, successes, and failures while retaining existing counters and failure details.
7. Restructure the overtime review child panel presentation around its current filters, grouped employee results, decision dialogs, batch confirmation, blocker explanations, sticky selection actions, empty states, and responsive behavior without changing its event interface.
8. Reuse semantic design tokens and existing `x-ui` components where suitable. Any shared status or dialog presentation must remain accessible and presentation-only.

### Out of Scope

- Any edit to `app/Livewire/Nomina/Revisar.php` or `resources/views/livewire/nomina/revisar.blade.php`.
- Any modification to `openspec/changes/flexible-single-shift-payroll/`.
- Payroll calculation, attendance policy, overtime decision rules, persistence rules, queue behavior, export behavior, or audit behavior.
- Upload parsing, validation, accepted-file contracts, or producer behavior for stored statuses.
- Deletion-policy redesign, a cancellation workflow, or processed-period reopening redesign.
- Broadening `Nomina\Procesar` route eligibility to render `cancelled` periods.
- Converting the state-changing export GET to another HTTP interaction.
- Refactoring existing parent/child Livewire event contracts or payloads.
- Application or test implementation during this proposal phase.

## Preserved Status Semantics

The UI may group statuses into five visual phases—period, upload, review, process, approve/export—but those phases are not persisted states and must not authorize or perform transitions.

| Status | Semantics that remain unchanged | Presentation direction |
| --- | --- | --- |
| `draft` | Newly created and uploadable; existing review/start checks still apply | Preparation with upload as the likely next action |
| `uploaded` | Stored uploadable state; no new meaning or producer is introduced | File present and further upload allowed; do not imply validation completion |
| `validating` | Mutable attendance review; not uploadable | Review in progress with blockers and review CTA |
| `validation_failed` | Uploadable error state | Explain correction and re-upload availability |
| `ready` | Review passed or explicitly advanced; processing may be requested | Ready to process, but not processed |
| `processing` | Payroll processor active; attendance locked | Active and non-editable; no duplicate processing CTA |
| `processed` | Frozen current results exist; locked; approval or existing reopen behavior available | Results review and approval stage |
| `approved` | Approval actor/time recorded; locked; export allowed | Finalized for export with immutable-risk messaging |
| `exported` | Export occurred; locked; export remains available | Completed/exported, not recalculated |
| `cancelled` | Locked terminal state | Terminal communication with no editing CTA |

The exact transition model remains:

- creation → `draft`
- review save/reopen → `validating`
- readiness/start checks → `ready`
- payroll processing → `processing` → `processed`
- approval → `approved`
- first approved export → `exported`
- processed reopening → `validating`, preserving prior result generations
- audited soft deletion remains separate from `cancelled`

No status may be added, removed, collapsed, reordered, renamed, or inferred. Payroll-run statuses and overtime-batch/item statuses remain separate concepts from pay-period status.

## UX and Visual Decisions

### Workflow orientation and status

- Use a presentation-only five-phase stepper on the period overview and processed-result screen; do not require adoption by the frozen review parent.
- Centralize exact labels, semantic tones, phase placement, and explanatory copy.
- Render unknown states explicitly as unknown rather than silently treating them as pending.
- Keep authorization and action availability in existing policies, model methods, tenant gates, and route constraints—not in presentation metadata.

### CTAs and blockers

- Show or enable only actions the current user can execute, including upload under `files.upload` and review under `marks.manage`, while preserving direct-route authorization.
- Preserve current route parameters and `wire:navigate` behavior.
- Explain disabled or blocked actions in adjacent text; do not rely on color or opacity.
- Preserve deletion eligibility and all required reason/audit behavior.

### Progress and feedback

- Payroll-run progress remains qualitative and polls every three seconds only while active. It must preserve delayed queue warnings, abandoned-run recovery, retry lineage, terminal stop, one-time events, verified-current-run redirects, and sanitized failures.
- Overtime-batch progress may use a determinate bar because real counts exist. Copy must distinguish completion from success and failure without redefining counters.
- Add page-level success/error feedback where local feedback is currently absent, including approval results.
- Preserve live regions and avoid repeated announcements after terminal states.

### Empty and responsive states

- Provide purposeful states for no periods, no payroll results, and filters with no matches, each with a permitted next action when one exists.
- Keep scan-friendly tables at larger widths. At small widths, use stacked metadata/actions or a clearly labeled horizontal-scroll region.
- Allow page actions and filters to wrap or stack without hiding context or primary actions.

### Confirmation dialogs and risk communication

- Approval requires an explicit confirmation before invoking the existing transition. Cancellation leaves the period `processed` and makes no mutation.
- Recheck authorization and status when confirmation is submitted. A stale or concurrent approval must follow existing row-lock/race behavior rather than trusting modal state.
- Preserve approval metadata exactly: the existing actor and time fields remain the source of truth.
- Deletion and overtime dialogs retain their existing reasons, immutable candidate details, interval constraints, stale-selection fingerprints, and 500-target batch ceiling.
- Dialogs must provide `role="dialog"`, accessible name and description, labeled fields, initial focus, Escape close where safe, focus return, and loading/disabled semantics.
- Export copy may explain that the first approved export finalizes the visible status, but export transport and transition behavior remain unchanged.

### Design system

Prefer semantic `brand`, `surface`, `border`, `text`, `success`, `warning`, and `danger` tokens and existing page header, card, stat card, badge, alert, empty state, button, input, select, textarea, loading button, and loading overlay components. Avoid introducing a parallel visual vocabulary. Shared presentation components must not become a transition or authorization engine.

## Functional and Transition Non-Goals

- Do not change who may create, upload, review, delete, process, retry, recover, decide overtime, approve, or export.
- Do not change active-company or tenant isolation, including super-admin context handling.
- Do not change validation, overlap, slug, evidence, projection, canonical-minute, selection, batch-size, polling, retry, recovery, or terminal-event behavior.
- Do not make upload available outside `draft`, `uploaded`, and `validation_failed`.
- Do not make approval available outside `processed` or export available outside `approved`/`exported`.
- Do not treat a visual step as proof that a domain transition occurred.
- Do not invent percentage completion for payroll processing.
- Do not make `cancelled` reachable through the processed-results route as part of this work.

## Affected Areas

| Area | Proposed impact |
| --- | --- |
| `app/Livewire/Nomina/Index.php` and matching view | Presentation metadata, permission-correct actions, responsive overview, deletion dialog |
| `app/Livewire/Nomina/Procesar.php` and matching view | Results UX, empty states, approval confirmation, feedback, responsive finalization |
| `PayrollRunProgress` component and view | Qualitative progress, feedback, accessible announcements |
| `OvertimeBatchProgress` component and view | Determinate count presentation and failure feedback |
| `OvertimeReviewPanel` component and view | Presentation/accessibility restructuring with unchanged contracts |
| Shared Nómina presentation/UI modules | Status labels, tones, phase/copy, and possibly reusable dialog presentation |
| Focused feature/design-system tests | Strict-TDD protection of behavior and rendered accessibility semantics |

The frozen `Nomina\Revisar` parent and its Blade view are explicitly unaffected.

## TDD Acceptance and Protection Areas

Implementation must proceed in focused RED → GREEN → REFACTOR cycles through existing public Livewire interfaces and rendered HTML.

- **Period lifecycle:** preserve `draft` creation, tenant-derived company, overlap/slug validation, upload redirect, reason-required deletion, associated-file soft deletion, and audit metadata.
- **Authorization and tenancy:** preserve policy checks on every direct action and route; test CTA visibility separately from server-side enforcement, active-company isolation, forged cross-tenant identifiers, and no-company guidance.
- **Upload matrix:** prove that only `draft`, `uploaded`, and `validation_failed` expose and accept upload.
- **Status presentation:** prove all ten statuses map to the intended exact label, semantic tone, phase, and copy; prove unknown states remain visibly unknown.
- **Workflow navigation:** preserve route parameters and navigation behavior without edits to either frozen review file.
- **Payroll progress:** preserve no-percentage output, three-second active polling, terminal stop, delayed warnings, recovery, retry lineage, one-time events, blockers, sanitized errors, and verified-current-run redirect.
- **Overtime:** preserve filter normalization, paginator isolation, grouped rows, candidate details, blocked mutation, selection reset, stale fingerprint protection, 500-item ceiling, actor/tenant scoping, counter semantics, failure details, terminal events, and narrowly scoped loading feedback.
- **Processed results:** preserve current-generation projection, canonical-minute display, filters, evidence, locked messaging, approval/export permissions, and export availability.
- **Approval confirmation:** replace the existing immediate/no-confirmation expectation with tests for opening, accessible naming/description, cancellation without mutation, confirmation, server-side permission/status recheck, duplicate-submit prevention, row-locked concurrent/race outcomes, and unchanged actor/time metadata.
- **Accessibility and responsive behavior:** assert semantic component variants, dialog roles/names, labels, focus hooks, live regions, disabled/busy semantics, blocker text, responsive classes, and useful empty states without brittle full-page snapshots.

## Risks and Mitigations

| Risk | Mitigation |
| --- | --- |
| Shared status metadata becomes a second state machine | Keep it presentation-only and test transitions through existing domain paths |
| CTAs appear valid when permission, tenant, run, or model gates disagree | Derive action availability from existing authorization and model interfaces; preserve direct-route checks |
| Approval confirmation weakens race or permission guarantees | Submit through the existing approval path and recheck status/permission under the existing row lock |
| UI implies false processing precision | Keep payroll progress qualitative; use determinate progress only for real overtime-batch counts |
| Modal restyling causes accessibility regressions | Protect names, descriptions, focus behavior, keyboard close/return, and busy states with focused tests |
| Responsive conversion hides evidence or actions | Retain desktop tables and test small-screen stacked/scroll presentation |
| Dead `cancelled` result branches gain misleading polish | Do not broaden `Procesar` mounting or route eligibility |
| Related overtime work causes domain drift | Do not modify or reinterpret `flexible-single-shift-payroll` artifacts or policies |
| Review workload exceeds the agreed budget | Stop at the `ask-on-risk` delivery gate before implementation; do not infer a chain or exception |

## Rollback Plan

The implementation must remain presentation-oriented so it can be rolled back by reverting the new/shared presentation mapping, view composition, dialog state, and focused UI tests. Existing persisted statuses, payroll results, approval metadata, audit data, queue records, and exports require no migration or data rollback. If approval-confirmation UI is reverted, the underlying row-locked approval method and its authorization/metadata behavior remain intact.

## Review Workload and Delivery Gate

The expected implementation spans shared presentation code, five UI surfaces, and focused behavioral/accessibility tests and is likely to exceed the 400 changed-line review budget. Under the confirmed `ask-on-risk` strategy, implementation must stop before delivery planning or code changes and ask for a human decision among a cohesive split, an explicitly approved `size:exception`, or reduced scope. No chain strategy or exception is selected by this proposal.

Potential review slices, if splitting is chosen later, are:

1. Shared pay-period presentation plus period overview.
2. Processed results plus accessible approval confirmation.
3. Payroll/overtime progress plus overtime panel presentation.

These are planning candidates only and do not pre-authorize a chain.

## Success Criteria

- [ ] Operators can identify the current phase, exact status, blockers, risk, and next permitted action without changing any domain semantics.
- [ ] All ten exact statuses retain their current meanings and transitions, and visual phases introduce no persisted state.
- [ ] `processed` → `approved` requires explicit accessible confirmation while preserving permission checks, row locking, race behavior, and approval actor/time metadata.
- [ ] Payroll progress remains qualitative; overtime-batch progress uses only real counts and accurately distinguishes successes and failures.
- [ ] Empty, failure, delayed, locked, and no-permission situations provide actionable and accessible feedback.
- [ ] Period, result, progress, and overtime interfaces are responsive and use the repository semantic design system.
- [ ] Existing authorization, tenant isolation, audit, polling, event, payload, evidence, projection, and export contracts remain protected by focused tests.
- [ ] Neither frozen `Revisar` file nor `openspec/changes/flexible-single-shift-payroll/` is modified.
- [ ] Implementation does not begin past the anticipated 400-line risk without the required `ask-on-risk` decision.
