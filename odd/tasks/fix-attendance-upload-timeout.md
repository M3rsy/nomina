# Fix attendance upload timeout

## Goal
Make normal attendance files such as a 69 KB GLG export complete within the synchronous upload request without weakening payroll locking, tenant isolation, duplicate detection, or manual-mark invariants.

## Issue
- Approved issue: [#390](https://github.com/M3rsy/nomina/issues/390).

## Scope
- Add a regression that exposes query amplification as attendance rows grow.
- Remove repeated schedule-assignment, publication, and work-schedule lookups from per-row validation.
- Reduce avoidable per-row raw-mark writes where behavior can be preserved.
- Preserve transactional rollback and stored-file cleanup on ordinary ingestion failures.

## Non-goals
- Increasing PHP's execution-time limit.
- Moving ingestion to a queue or introducing a new background-job UX.
- Changing attendance parsing, work-date semantics, payroll policy behavior, or duplicate rules.
- Changing payroll lock ordering or tenant boundaries.

## Tasks
- [x] 1. Add a failing performance regression that bounds schedule-resolution query growth for a representative multi-row upload.
- [x] 2. Implement request-scoped batch resolution and persistence improvements while preserving validation semantics.
- [x] 3. Verify focused behavior, rollback/cleanup coverage, diagnostics, formatting, and the relevant regression suite.

## Acceptance criteria
- Schedule assignment/publication/schedule query counts do not grow linearly with uploaded rows.
- A representative large upload completes without relying on a higher `max_execution_time`.
- Duplicate, out-of-period, unknown-employee, locked-period, and manual-pair behavior remains unchanged.
- Tenant scoping and canonical payroll lock ordering remain intact.
- Failed ingestion rolls back database state and removes the newly stored attendance file when PHP can unwind the exception.

## Evidence
- RED: the 100-row regression issued 5,100 assignment/publication/schedule queries and took 4.12 seconds.
- Secondary RED: after schedule preloading, the same regression still issued 104 attendance-generation queries.
- Focused GREEN: schedule queries are bounded independently of row count and per-row attendance-generation sum queries are eliminated.
- A representative 1,200-row probe across 20 employees and 30 days passed in 15.37 seconds on SQLite, below the original 30-second request limit.
- Parsed pending marks are inserted in chunks of 50 while preserving JSON metadata; status validation remains sequential so manual-pair semantics still observe earlier accepted rows.
- Relevant regression gate: 102 tests and 529 assertions passed across upload, validator, occurrence resolver, and attendance analysis coverage.
- Full suite passed with an explicit 256 MB test-process limit: 1,114 tests, 6,005 assertions, 3 skipped.
- The default 128 MB full-suite process exhausted accumulated memory later in the unrelated Excel-template test; that test passed alone (1 test, 4 assertions).
- Pint, `git diff --check`, and LSP diagnostics passed.
- Native review was unavailable because the package-local Gentle AI binary is missing; no review lineage was created.
- Work-unit commit: `2768454` (`fix(attendance): bound upload validation queries`).

## Rollback boundary
Revert the FileValidator/resolver batching changes and their focused regressions. No migration or data rollback is required.
