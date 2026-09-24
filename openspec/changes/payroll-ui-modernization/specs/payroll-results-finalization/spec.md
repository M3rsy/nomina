# Payroll Results and Finalization Specification

## Purpose

Define accessible review, approval, and export presentation for frozen payroll results while preserving authorization, projection, locking, and transition behavior.

## Requirements

### Requirement: Processed-results context and projection

The results page MUST remain route-eligible only for `processed`, `approved`, and `exported` periods. It MUST present period identity, the shared five-phase/status context, semantic summaries, current-generation frozen results, canonical-minute values, existing filters, and existing evidence without changing projection or evidence semantics. It MUST NOT make `cancelled` reachable through this route.

#### Scenario: Eligible result review

- GIVEN a tenant-visible period in `processed`, `approved`, or `exported`
- WHEN an authorized actor opens its results page
- THEN the page shows the period and exact status context
- AND summaries and rows come from the current frozen result generation
- AND time values retain their canonical-minute presentation

#### Scenario: Ineligible status

- GIVEN a period in any other status, including `cancelled`
- WHEN the standard results route is requested
- THEN the existing warning and redirect behavior is preserved
- AND the route is not broadened by presentation logic

### Requirement: Useful result and filter empty states

The results page MUST distinguish an eligible period with no payroll results from filters that match no current result. Each empty state MUST explain the condition and MUST offer only a permitted, context-appropriate next action when one exists.

#### Scenario: No frozen results

- GIVEN an eligible period has no current-generation result rows
- WHEN the results page renders
- THEN it presents a no-results explanation rather than an empty table
- AND it does not imply that processing completed successfully

#### Scenario: Filters match no results

- GIVEN the period has current-generation results but the active filters match none
- WHEN the filtered view renders
- THEN it identifies the no-match condition
- AND it offers a way to clear or change filters without changing payroll data

### Requirement: Accessible evidence and locked-risk communication

Evidence disclosure MUST preserve the existing frozen evidence and MUST have an accessible control, name, and relationship to the disclosed content. The page MUST explain locked or immutable states, blockers, and consequential risks in text rather than by color alone.

#### Scenario: Inspect frozen evidence

- GIVEN a visible payroll result has evidence
- WHEN an actor opens its evidence disclosure
- THEN the existing evidence is exposed without mutation or reinterpretation
- AND the disclosure control and content are programmatically associated

#### Scenario: Locked finalized period

- GIVEN a period is `approved` or `exported`
- WHEN the results page renders
- THEN it explains that the period is locked
- AND it does not offer editing or approval controls

### Requirement: Explicit approval confirmation

The system MUST require explicit accessible confirmation before invoking the existing `processed` to `approved` action. The dialog MUST describe the consequence and immutable risk, provide confirm and cancel controls, expose an accessible name and description, set safe initial focus, close on Escape, return focus to its trigger, expose pending busy/disabled state, and prevent duplicate submission.

#### Scenario: Open approval confirmation

- GIVEN an authorized actor views a `processed` period that can be approved
- WHEN the actor selects approve
- THEN an approval confirmation opens without changing the period
- AND the dialog's accessible name and description communicate the approval consequence
- AND initial focus is placed on a safe dialog control

#### Scenario: Cancel approval

- GIVEN the approval confirmation is open
- WHEN the actor cancels or presses Escape
- THEN the period remains `processed`
- AND no approval actor or time is recorded
- AND focus returns to the approval trigger

#### Scenario: Confirm approval once

- GIVEN the confirmation is open for an approvable `processed` period
- WHEN the actor confirms
- THEN the existing approval path is invoked exactly once
- AND the confirmation controls expose busy and disabled state while pending
- AND success or failure feedback is presented at page level

### Requirement: Approval remains server-authorized and race-safe

Confirmation state MUST NOT authorize approval. On confirmation, the system MUST recheck the existing permission, tenant context, and current `processed` status and MUST use the existing row-locked approval behavior. Existing concurrent and stale outcomes and the existing approval actor/time fields MUST remain authoritative.

#### Scenario: Permission changes while dialog is open

- GIVEN an actor opens confirmation while authorized
- AND approval permission is removed before submission
- WHEN the actor confirms
- THEN approval is rejected by the server-side authorization check
- AND the period and approval metadata remain unchanged

#### Scenario: Concurrent approval

- GIVEN two actors opened confirmation for the same `processed` period
- WHEN one approval completes before the other confirmation is submitted
- THEN the later request follows the existing row-lock and stale-status outcome
- AND it does not overwrite or duplicate the authoritative approval actor/time metadata

### Requirement: Status-aware approval and export actions

Approval MUST be offered only for `processed` and only when the current actor is permitted to approve. Export MUST be offered only for `approved` and `exported` and only when the current actor is permitted to export. Export messaging MUST explain that the first approved export finalizes the visible status as `exported`, while export transport, locking, and repeated-export behavior remain unchanged.

#### Scenario: Processed action state

- GIVEN an authorized actor views a `processed` period
- WHEN actions render
- THEN approval is available through confirmation
- AND export is unavailable

#### Scenario: Approved or exported action state

- GIVEN an authorized actor views an `approved` or `exported` period
- WHEN actions render
- THEN export is available
- AND approval is unavailable
- AND copy distinguishes the first finalizing export from a later export without changing the existing export interaction

### Requirement: Responsive semantic results presentation

The results page MUST use existing semantic design tokens and suitable `x-ui` components. Headers, actions, filters, summaries, result identity, evidence, and primary actions MUST remain usable when controls wrap or stack. Large viewports SHOULD retain scan-friendly tabular results; small viewports MUST use stacked rows or a clearly labeled horizontal-scroll region.

#### Scenario: Results on a narrow viewport

- GIVEN summaries, filters, results, and finalization actions are present
- WHEN the page is displayed at a small viewport width
- THEN controls wrap or stack without hiding period context or primary actions
- AND each result retains a discoverable association between labels and values
