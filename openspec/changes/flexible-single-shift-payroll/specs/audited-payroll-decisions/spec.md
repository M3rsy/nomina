# Audited Payroll Decisions Specification

## Purpose

Define audited decisions and freshness.

## Requirements

### Requirement: Informational entry variation

Entry through 06:20 MUST create no variation. Later entry MUST create one nonblocking schedule-entry-variation only after 480 actual minutes. It MUST NOT reduce ordinary minutes or penalize punctuality.

#### Scenario: Tolerance boundary

- GIVEN an eligible interval 06:20–14:20
- WHEN reviewed
- THEN it has 480 ordinary minutes and no entry variation

#### Scenario: Late entry with completed quota

- GIVEN an eligible interval 07:00–15:00
- WHEN review is requested
- THEN it has 480 ordinary minutes and one informational variation
- AND the variation neither blocks processing nor changes pay

#### Scenario: Late entry without quota

- GIVEN an eligible interval 07:00–14:00
- WHEN reviewed
- THEN it has 420 ordinary minutes and a 60-minute `daily_shortfall`
- AND no entry variation is emitted

### Requirement: Audited acknowledgement

Acknowledgement MUST append actor, reason, time, and current fingerprint without altering attendance, readiness, or `Tiempo pagable`. A stale fingerprint MUST append nothing.

#### Scenario: Acknowledge current variation

- GIVEN a current unacknowledged variation
- WHEN an authorized actor acknowledges it with a reason
- THEN review exposes its audit data and remains nonblocking
- AND recognized and payable minutes are unchanged

### Requirement: Shortfall lifecycle

A new `daily_shortfall` MUST be pending and block readiness and processing. GRANTED MUST add its minutes to ordinary `Tiempo pagable`; REJECTED MUST close it unpaid. REVOKED MAY supersede only GRANTED and MUST restore effective pending status.

#### Scenario: Pending seven-hour deficit

- GIVEN seven hours and a pending 60-minute shortfall
- WHEN readiness or processing is requested
- THEN readiness reports a blocker and processing writes no result

#### Scenario: Grant or reject

- GIVEN 420 ordinary actual minutes and a current 60-minute shortfall
- WHEN it is decided GRANTED or REJECTED
- THEN GRANTED yields 480 ordinary payable minutes
- AND REJECTED closes unpaid at 420 ordinary payable minutes

#### Scenario: Valid and invalid revocation

- GIVEN a current GRANTED shortfall decision
- WHEN it is REVOKED
- THEN audit shows REVOKED while effective status is pending and blocking
- AND REVOKED against pending or REJECTED is refused without an append

### Requirement: Immutable overtime resolution

The system MUST preserve the full candidate/fingerprint separately from approval. Review MUST block until full approval, rejection, or individual approval of one nonempty, whole-minute contiguous subinterval. It MUST derive wall-clock rates, reject the complement, and close review.

#### Scenario: Full decision

- GIVEN a current detected candidate
- WHEN an actor fully approves or fully rejects it
- THEN the candidate remains unchanged
- AND payable overtime is respectively all or zero

#### Scenario: Approve 17:00–18:00

- GIVEN a detected candidate 17:00–19:00
- WHEN 17:00–18:00 is individually approved
- THEN 60 minutes at +25% are approved; 18:00–19:00 is rejected

#### Scenario: Approve 18:00–19:00

- GIVEN a detected candidate 17:00–19:00
- WHEN 18:00–19:00 is individually approved
- THEN 60 minutes at +50% are approved; 17:00–18:00 is rejected

#### Scenario: Approve across a rate boundary

- GIVEN a detected candidate 17:00–19:00
- WHEN 17:30–18:30 is individually approved
- THEN approval is 30 minutes at +25% plus 30 at +50%
- AND both complements are rejected and review closes

#### Scenario: Batch restriction

- GIVEN multiple current overtime candidates
- WHEN a batch decision is requested
- THEN only whole-candidate approval or rejection is accepted
- AND partial intervals are refused without decisions

### Requirement: Append-only fresh decisions

Every shortfall/overtime decision MUST append actor, reason, time, fingerprint, and supersession without changing prior records. A fingerprint not matching current marks, revisions, calendar, profile, or candidate MUST fail without affecting readiness or pay.

#### Scenario: Stale decision protection

- GIVEN displayed facts change before decision submission
- WHEN their earlier fingerprint is submitted
- THEN the decision is rejected as stale and no audit record is appended
- AND the current fact remains unresolved
