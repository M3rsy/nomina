# Read-compatible attendance decision identity

**Status:** accepted
**Date:** 2026-08-19
**Context:** [ADR 0002 — Nómina basada primero en duración](0002-duration-first-payroll.md)

## Problem

The publication-identity release added `publication_id` and `payroll_policy_key` to attendance fingerprints. Decisions written by the previous release contain the same assignment, schedule, calendar, fact generation, marks, and revisions, but use the earlier fingerprint and derived candidate/deficit key. A second discontinuity occurs when `duration-first-v2` is activated on an already reviewed work date: the prospective assignment and publication begin on that date, while the immutable decision still names the immediately preceding `schedule-overlap-v1` fact. Treating either row as unrelated makes approved overtime pending and granted attendance exceptions unpaid.

The decision tables are append-only. Rewriting or backfilling audited rows would destroy the exact identity used when a person made the decision.

## Decision

Canonical fingerprints and keys remain publication-aware for every new attendance fact and decision. Compatibility is read-only and has two narrowly bounded sources:

1. For `schedule-overlap-v1`, derive the exact fingerprint emitted before publication identity existed: the canonical inputs with only `publication_id` and `payroll_policy_key` omitted.
2. When a unique `schedule-overlap-v1` assignment/publication ends immediately before a unique `duration-first-v2` assignment/publication begins on the reviewed work date, reconstruct the predecessor analysis with the current marks, revisions, fact generation and calendar generation. A predecessor identity is attached only when the two analyses produce one unambiguous equivalent payable fact: exact interval, minutes and bands for overtime, or one exact total deficit with equal minutes and bands.

The second rule does not infer evidence from shape. A stored decision must still match the recomputed predecessor key, fingerprint and full predecessor segment. The shape check only proves that this exact predecessor fact and the current fact have the same payment meaning.

`AttendanceDecisionIdentity` owns each inseparable key/fingerprint pair together with the segment shape from which the key was derived. `AttendanceDecisionMatcher` owns decision resolution for payroll evaluation, review snapshots, UI projections, and recorders:

1. Try the canonical key and require its canonical fingerprint.
2. Only when no canonical key exists, try verified compatible identities in deterministic order and require each identity's paired fingerprint and stored segment shape.
3. In either case, require a valid decision state and versioned overtime conservation.
4. Otherwise fail closed and leave the fact pending or unpaid.

`AttendanceShiftAnalysisResolver` is the single orchestration module for analysis plus compatibility enrichment. Payroll review and both recorders use this interface instead of independently reproducing the sequence.

Recorder changes may append a canonical decision that supersedes a matcher-verified compatible root. `AttendanceDecisionAppender` first distinguishes a parent matching the current canonical identity from one matching a noncanonical compatible identity. Canonical history keeps the ordinary append path. Every compatible match, including the publication-format V1→V1 transition and the policy V1→V2 transition, is revalidated before the appender sets a one-use transaction-local PostgreSQL capability. The capability binds decision type, parent id/key/fingerprint, child record version and canonical key/fingerprint, company, pay period, employee, and work date. The append-only trigger consumes it; a direct shape-only insert, a mismatched child, or reuse is rejected. The appender never mutates the historical row or authorizes a root from another tenant, payroll context, date, evidence generation, or ambiguous predecessor transition.

## Rejected alternatives

- **Backfill audited decisions:** rejected because decisions are append-only and their original evidence identity must remain reproducible.
- **Ignore publication or policy identity permanently:** rejected because unrelated future publications and policy changes must invalidate old decisions.
- **Match only boundaries/minutes:** rejected because changes to marks, revisions, attendance fact generation, assignment, schedule, or calendar could silently reactivate stale approvals.
- **Accept either a legacy key or fingerprint independently:** rejected because the pair is the released identity; accepting half permits inconsistent records to bind.
- **Let the database infer compatibility from equal minutes/bands:** rejected because shape parity cannot prove the stored V1 row is the exact reconstructed predecessor.

## Consequences

- Historical decisions survive the publication-identity format transition and the exact same-date `schedule-overlap-v1` → `duration-first-v2` transition only when the predecessor evidence is reconstructible and payment semantics are equal.
- Mark/revision, fact-generation, holiday calendar, and segment changes remain stale by construction. Assignment, schedule, or publication changes are also stale unless they are the one exact predecessor transition verified above.
- New snapshots and decisions continue storing canonical identities; legacy aliases are transient analysis data and require no schema migration.
- Any supersession through a noncanonical compatible identity requires the application verifier and database trigger to participate in the same transaction; the authorization cannot escape or authorize a second insert. Ordinary same-canonical-identity history remains append-only without compatibility authorization.
