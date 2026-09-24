# Payroll Progress Presentation Specification

## Purpose

Define truthful and accessible payroll-run progress without inventing precision or changing polling, recovery, retry, security, or terminal behavior.

## Requirements

### Requirement: Qualitative payroll-run progress

Payroll-run progress MUST present `queued`, `processing`, `completed`, and `failed` as qualitative states. Active states MUST use an indeterminate indicator and MUST NOT display or imply a completion percentage. The presentation MUST distinguish payroll-run state from pay-period and overtime-batch state.

#### Scenario: Active run has no fabricated precision

- GIVEN a payroll run is `queued` or `processing`
- WHEN progress renders
- THEN it names the qualitative state and displays an indeterminate active indicator
- AND it displays no percentage or determinate completion value

#### Scenario: Terminal result is clear

- GIVEN a payroll run is `completed` or `failed`
- WHEN progress renders
- THEN it communicates the terminal outcome in text and semantic styling
- AND it does not present the run as still active

### Requirement: Preserve active polling and terminal announcements

The component MUST poll every three seconds only while a run is active. It MUST stop polling at a terminal state, dispatch each existing terminal event at most once, preserve live-region announcements, and avoid repeatedly announcing an unchanged terminal state. A completed-run redirect MUST remain limited to the verified current run.

#### Scenario: Poll active run

- GIVEN the current verified run is `queued` or `processing`
- WHEN its progress component is active
- THEN it polls on the existing three-second interval
- AND it remains scoped to the authorized actor's period and company

#### Scenario: Stop at terminal state

- GIVEN the run becomes `completed` or `failed`
- WHEN the terminal state is observed
- THEN polling stops
- AND the existing terminal event is emitted once
- AND only a verified current completed run can trigger the existing results redirect

### Requirement: Delayed and recoverable run guidance

The presentation MUST preserve the existing delayed-queue warning and abandoned-run recovery eligibility. It MUST explain what the operator can do next, MUST show recovery only when the existing server-side recovery conditions and permissions allow it, and MUST keep direct recovery authorization and tenant checks.

#### Scenario: Delayed queued run

- GIVEN a queued run exceeds the existing delay threshold
- WHEN progress renders
- THEN it explains that worker pickup is delayed
- AND it does not claim failure or display fabricated progress

#### Scenario: Recover abandoned run

- GIVEN an active run's lease satisfies the existing recoverable condition and the actor is permitted
- WHEN recovery is offered and invoked
- THEN the existing recovery path is used
- AND feedback communicates the refreshed outcome without weakening authorization or tenant scope

### Requirement: Failure and retry feedback

Failed-run presentation MUST expose actionable, sanitized failure information and MUST NOT leak raw exceptions or sensitive details. Retry MUST remain available only through the existing authorization and failed-run conditions, MUST preserve retry lineage, and MUST clearly identify the newly active retry outcome.

#### Scenario: Sanitized failure

- GIVEN a payroll run fails with recorded telemetry
- WHEN failure feedback renders
- THEN it presents the permitted sanitized code or message and recovery guidance
- AND it does not expose raw internal exception details

#### Scenario: Retry failed run

- GIVEN an authorized actor retries a current failed run
- WHEN the existing retry request succeeds
- THEN the replacement run preserves its lineage to the failed run
- AND presentation returns to qualitative active progress
- AND the existing retry event and duplicate protections remain unchanged

### Requirement: Semantic and accessible progress feedback

Progress, delayed warnings, failures, retry results, and recovery results MUST use existing semantic design-system tokens or components. State and risk MUST be communicated in text and accessible live regions rather than by motion, color, or opacity alone. Motion SHOULD respect reduced-motion preferences.

#### Scenario: Nonvisual progress interpretation

- GIVEN a screen-reader user observes a run from active to terminal
- WHEN status updates occur
- THEN meaningful status and terminal feedback is announced without repeated terminal announcements
- AND no required meaning depends only on animation or color
