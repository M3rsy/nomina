# Effective-Dated General Profile Specification

## Purpose

Define effective-dated general-profile publication and resolution.

## Requirements

### Requirement: General profile version

The system MUST create a general profile version with 06:00–14:00 Monday–Saturday and Sunday off. Activation MUST start on the earliest configured payroll period not begun when requested; a begun period is ineligible even if unprocessed.

#### Scenario: Choose next not-started period

- GIVEN a current payroll period has begun and a later configured period has not
- WHEN general-profile activation is requested
- THEN the new version becomes applicable on the later period's first day
- AND the prior version remains applicable before that date

#### Scenario: No eligible period

- GIVEN no configured payroll period starts after the request time
- WHEN activation is requested
- THEN activation fails closed with no profile or assignment changes

### Requirement: Prospective current-employee assignment

Activation MUST prospectively assign every current employee from its effective date under payroll locks. It MUST atomically preserve prior profile versions, assignment rows, and locked results.

#### Scenario: Prospective assignment

- GIVEN current employees use the prior general version
- WHEN the new version is activated
- THEN each employee resolves the prior version before activation and the new version on activation
- AND prior profiles and assignments remain historically readable

#### Scenario: Locked-period conflict

- GIVEN a required prospective assignment would change coverage intersecting a locked period
- WHEN activation is requested
- THEN the entire activation is refused without partial assignments
- AND locked assignments and results remain byte-for-byte unchanged

### Requirement: Sole date-effective resolution for new employees

New employees MUST resolve only the one general profile applicable on the assignment date. Zero or multiple matches MUST fail closed, never selecting by creation order, active flag, or first match.

#### Scenario: Hire before, on, and after activation

- GIVEN exactly one general profile applies on each tested assignment date
- WHEN employees are created before, on, and after activation
- THEN the pre-activation employee receives the prior version
- AND employees on or after activation receive the new version

#### Scenario: Ambiguous or missing profile

- GIVEN zero or multiple general profiles apply on the assignment date
- WHEN employee creation requests automatic assignment
- THEN creation fails with an explicit profile-resolution error
- AND no schedule assignment is created

### Requirement: Idempotent and concurrent-safe activation

Repeated or concurrent requests for the same intended version and activation date MUST converge on one profile version and one prospective assignment per employee. Conflicting requests MUST fail atomically; retries MUST NOT duplicate, shorten, or reorder historical applicability.

#### Scenario: Idempotent retry

- GIVEN activation completed successfully
- WHEN the same activation request is retried
- THEN the same effective version and assignments are returned
- AND no additional profile or assignment rows are created

#### Scenario: PostgreSQL concurrent activation

- GIVEN two PostgreSQL transactions concurrently request the same activation
- WHEN both complete
- THEN exactly one applicable general version and one new assignment per employee exist
- AND each response is success-equivalent or an explicit harmless concurrency conflict

#### Scenario: PostgreSQL applicability invariant

- GIVEN concurrent requests would create overlapping general-profile applicability for one company and date
- WHEN PostgreSQL commits are attempted
- THEN at most one conflicting applicability range commits
- AND public resolution never returns an arbitrary profile

### Requirement: Processing uses locked effective history

Payroll review and processing MUST resolve the assignment effective on each `Fecha laboral`; missing or ambiguous resolution MUST block both operations. Later activation MUST NOT change a previously locked or processed result.

#### Scenario: Fail-closed payroll resolution

- GIVEN a `Fecha laboral` with zero or multiple applicable assignments or profiles
- WHEN readiness or processing is requested
- THEN an explicit assignment/profile blocker is returned
- AND no payroll result is written
