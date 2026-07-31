# Immutable Payroll Reporting Specification

## Purpose

Define immutable snapshots, reports, and acceptance.

## Requirements

### Requirement: Canonical immutable daily snapshot

Processing MUST write one canonical integer-minute snapshot per employee/`Fecha laboral`: `Marca observada` values/revisions/duration; ordinary, +25%, +50%, +75%, +100%; shortfall minutes/state/reason; full detected and approved overtime intervals/rate splits; rejected complement; variation/acknowledgement audit; excluded transfer; and `rules_version`.

#### Scenario: Process a ready day

- GIVEN all decision blockers are resolved
- WHEN public payroll processing succeeds
- THEN the snapshot contains every applicable fact and exact minute bucket
- AND exporters can reproduce the result without attendance recalculation

#### Scenario: Blocked day writes nothing

- GIVEN a pending shortfall or unresolved overtime candidate
- WHEN processing is requested
- THEN processing reports it and writes no result

### Requirement: Insert-only processing and retry identity

An existing daily result MUST NOT be overwritten after reset or source/rule/profile change. An identical retry MAY return it; a conflicting retry MUST fail unchanged.

#### Scenario: Reset cannot rewrite history

- GIVEN a processed period is reset and current attendance differs
- WHEN processing is requested again
- THEN the request is rejected as conflicting
- AND the original snapshot and `rules_version` remain unchanged

### Requirement: Explicit legacy presentation

Pre-change rows MUST remain unmodified. Detail and exports MUST show unavailable fields null/blank, never inferred zero, label rows `LEGACY`, and retain historical `rules_version` when present.

#### Scenario: Export a historical row

- GIVEN a row lacks new decision or transfer fields
- WHEN either report is generated
- THEN those fields are blank/null and the row is labelled `LEGACY`
- AND no reprocessing or backfill occurs

### Requirement: Complete global and employee reports

Global Excel and employee stub MUST expose `Fecha laboral`; observed marks/revisions/duration; rate minutes; shortfall state/reason; detected, approved, and rejected overtime; variation/acknowledgement; excluded transfer; `rules_version`; and legacy status. Both MUST provide employee subtotals and document grand totals per minute bucket.

#### Scenario: Current report structure

- GIVEN current and legacy rows are selected
- WHEN either report is exported
- THEN every required column, employee subtotal, and grand-total field is present
- AND unknown legacy values remain distinct from numeric zero

### Requirement: Sum minutes before presentation conversion

Reports MUST total canonical integer minutes before decimal-hour conversion. Display rounding MUST NOT feed higher totals; a stub's grand total MUST equal its employee subtotal.

#### Scenario: Avoid accumulated display rounding

- GIVEN two daily rows each contain one minute in a bucket
- WHEN report totals are generated
- THEN subtotal and grand-total sources equal two minutes
- AND neither sums rounded daily hours

### Requirement: Strict behavior-first acceptance

Acceptance MUST proceed one public behavior at a time: behavior-named test RED, minimal GREEN, then REFACTOR while GREEN. Tests MUST use independent scenario literals, never private structure.

#### Scenario: Verify one acceptance behavior

- GIVEN the next unimplemented scenario
- WHEN its slice is developed
- THEN evidence records RED, GREEN, and post-refactor GREEN
- AND no later behavior enters that cycle

### Requirement: Issue-first Feature Branch Chain acceptance

Implementation MUST follow duplicate search and one approved feature issue. A draft/no-merge tracker MUST anchor a chain: child one targets it; later children target their predecessor. Slices SHOULD approximate 400 authored lines and MUST split before 800. Each PR MUST have exactly `type:feature` and a content-bound current-head review receipt; changes MUST invalidate it until renewed.

#### Scenario: Accept a chained slice

- GIVEN an issue-linked child has passing checks
- WHEN merge eligibility is evaluated
- THEN its target/diff follow the chain without unrelated slices
- AND its receipt identifies current content and head

#### Scenario: Content changes after review

- GIVEN a slice has a valid receipt
- WHEN reviewed content or head changes
- THEN merge eligibility is revoked until a new receipt is recorded
- AND the tracker remains no-merge
