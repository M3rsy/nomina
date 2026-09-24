# Overtime Review Presentation Specification

## Purpose

Define truthful overtime-batch progress and accessible overtime review presentation while preserving decision rules, event interfaces, selection safety, and tenant isolation.

## Requirements

### Requirement: Determinate overtime-batch count presentation

Overtime-batch progress MUST use the existing real counters to present a determinate completed-item count against total items. It MUST separately identify pending, processing, succeeded, and failed counts and MUST NOT describe successes alone as all processed items. Existing counter meanings and failure details MUST remain unchanged.

#### Scenario: Mixed batch progress

- GIVEN a batch contains pending, processing, succeeded, and failed items
- WHEN progress renders
- THEN the determinate value is based on completed items, where completed equals succeeded plus failed
- AND total, succeeded, and failed counts are separately labeled
- AND the UI does not redefine or overwrite stored counters

#### Scenario: Completed batch with failures

- GIVEN a terminal batch has both succeeded and failed items
- WHEN progress renders
- THEN it identifies completion with errors rather than full success
- AND it retains the existing bounded failure details

### Requirement: Secure batch polling and terminal behavior

Batch progress MUST preserve actor, tenant, and period scoping; active polling; terminal stop; unavailable-batch behavior; and one-time terminal events. The presentation MUST use accessible live feedback and MUST avoid repeated terminal announcements.

#### Scenario: Cross-tenant or different-actor batch

- GIVEN a batch identifier belongs to another company, period, or requesting actor
- WHEN polling attempts to load it
- THEN the batch is treated according to the existing unavailable behavior
- AND no counts or failure details are disclosed

#### Scenario: Batch reaches terminal state

- GIVEN an authorized visible batch becomes terminal
- WHEN progress observes the terminal state
- THEN polling stops
- AND the existing terminal event is dispatched once
- AND the final counts remain visible and accurately labeled

### Requirement: Accessible overtime filtering and grouped results

The overtime review panel MUST preserve URL-backed filter normalization, its isolated paginator, grouped employee results, exact candidate details, and current filter meanings. Filters and results MUST use accessible labels. The panel MUST distinguish no candidates from filters with no matches and MUST offer a non-mutating way to adjust or clear filters.

#### Scenario: Invalid URL filters

- GIVEN unsupported status, rate, or date values arrive through the URL
- WHEN the panel initializes
- THEN the existing normalization rules apply
- AND no expanded decision scope is inferred from invalid values

#### Scenario: No filter matches

- GIVEN overtime candidates exist but none match the active normalized filters
- WHEN the panel renders
- THEN it explains that filters produced no matches
- AND it provides a clear way to adjust or clear filters

### Requirement: Blockers remain explicit

When overtime mutation is blocked, the panel MUST keep relevant review information visible, MUST disable or omit mutation controls consistently with existing behavior, and MUST explain the blocker in adjacent text. Styling alone MUST NOT communicate blocked state, and presentation MUST NOT bypass the reactive blocker input.

#### Scenario: Blocked panel

- GIVEN the parent supplies `isBlocked` as true
- WHEN overtime candidates render
- THEN candidate information remains reviewable
- AND mutation controls cannot submit decisions
- AND adjacent text explains why action is unavailable

### Requirement: Individual decision dialog accessibility and invariants

Individual full, partial, and rejection dialogs MUST retain immutable candidate identity and details, required reason, and existing partial-interval constraints. Each dialog MUST provide an accessible name and description, labeled fields, safe initial focus, Escape cancellation where safe, focus return, and busy/disabled duplicate-submission protection. Submission MUST preserve the existing `overtime-decision-submitted` event name and payload contract.

#### Scenario: Cancel individual decision

- GIVEN an individual decision dialog is open for a candidate
- WHEN the actor cancels or presses Escape before submission
- THEN no decision event is dispatched
- AND focus returns to the opening control

#### Scenario: Submit partial decision

- GIVEN a current candidate and a nonempty whole-minute contiguous subinterval satisfying existing constraints
- WHEN the actor supplies the required reason and submits
- THEN the existing event and payload are dispatched without reinterpretation
- AND the immutable candidate details remain visible during confirmation

### Requirement: Batch decision dialog accessibility and safety

Batch confirmation MUST retain required common reason, immutable selection summary, normalized filter summary, stale-selection fingerprint protection, whole-candidate decision restriction, and the 500-target ceiling. The dialog MUST provide an accessible name and description, labeled fields, safe initial focus, Escape cancellation where safe, focus return, and busy/disabled duplicate-submission protection. Submission MUST preserve the existing `overtime-batch-submitted` event name and payload contract.

#### Scenario: Selection exceeds ceiling

- GIVEN the resolved pending selection exceeds 500 candidates
- WHEN the actor attempts to open batch confirmation
- THEN confirmation is blocked with guidance to narrow filters
- AND no batch event is dispatched

#### Scenario: Selection becomes stale

- GIVEN a batch confirmation is open
- AND the resolved selection or candidate fingerprint changes before submission
- WHEN the actor submits
- THEN the existing stale-selection protection rejects the request
- AND no mutation event is dispatched

#### Scenario: Submit current batch selection

- GIVEN at most 500 current pending candidates, an allowed whole-candidate decision, and a required common reason
- WHEN the actor confirms the batch
- THEN the existing event name and payload shape are preserved
- AND loading feedback is scoped to submission rather than unrelated selection controls

### Requirement: Sticky actions and responsive semantic presentation

The panel MUST use existing semantic design tokens and suitable `x-ui` components while preserving grouped content and decision affordances. Selection actions MAY remain sticky but MUST NOT obscure focused content or essential context. Filters, cards, candidate details, actions, and dialogs MUST wrap or stack on narrow viewports without hiding blockers or decision consequences.

#### Scenario: Narrow overtime review

- GIVEN grouped candidates, active filters, and selected pending candidates
- WHEN the panel is displayed at a narrow viewport width
- THEN labels, candidate facts, blocker text, and permitted actions remain discoverable
- AND sticky actions do not prevent keyboard users from reaching or viewing focused content

#### Scenario: Semantic risk communication

- GIVEN an overtime action has destructive, blocked, pending, successful, or failed significance
- WHEN it is presented
- THEN existing semantic design-system tones and text communicate the significance
- AND meaning does not depend on color alone
