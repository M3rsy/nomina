# Overtime batch performance

## Outcome
Confirming a batch of up to 500 overtime candidates queues work quickly without rendering the full payroll review, while isolated progress preserves auditing, idempotency, company isolation, fingerprints, append-only decisions, and payroll locking.

## Issue and branch
- Issue: [#396](https://github.com/M3rsy/nomina/issues/396)
- Branch: `perf/overtime-batch-flow`
- Base: `main` plus the local follow-up commits that already isolate the overtime panel, make parent submission renderless, mount progress independently, and accept `all: true` with `selected: []`.
- Unrelated local state: preserve the pre-existing `package-lock.json` modification.

## Context
Representative local period:
- approximately 66 employees;
- 1,191 raw marks;
- 249 current pending overtime candidates;
- Laravel 12, Livewire 3, PostgreSQL, database queue.

The feature branch inherits evidence and fixes from:
- `odd/tasks/overtime-approval-timeout.md`;
- `odd/tasks/overtime-approval-timeout-followup.md`;
- `odd/tasks/overtime-batch-submit-renderless.md`.

## Baseline evidence before this initiative
- `Revisar` mount/render: approximately 24.64s.
- `OvertimeReviewPanel` mount/render: approximately 8.34s.
- Original successful child batch submit: approximately 22.8s because it rescanned targets and rendered the panel.
- Follow-up child submit after stored-intent dispatch: approximately 0.016s with no HTML effect.
- Current authoritative parent action for 249 targets: approximately 16.52s, 294 queries, no HTML effect, temporary batch with 249 items.
- On `main`, `requestOvertimeBatchFromPanel` is not renderless and `submitOvertimeBatch` rescans before dispatch.
- Current worker processes 20 items per execution and releases normal continuation with the same 10-second error backoff.

## Hypotheses to validate
1. Normal successful chunk continuation adds about 120 seconds of avoidable idle time for 249 items.
2. Parent resolution and requester snapshot construction still calculate the period more than once before enqueue.
3. Requester holds a pay-period row lock while performing the expensive full-period scan.
4. `createMany()` emits one insert per item and can be replaced safely by a bounded bulk insert.
5. Worker reloads stable batch, actor, period, and employee context per item.
6. `uploaded_file_id` bypasses the SQL projection and forces in-memory review resolution.
7. Queue worker absence is not distinguishable enough from healthy queue latency.
8. Event naming currently conflates batch acceptance with final recorded decisions.
9. Loading buttons without scoped targets can react to unrelated operations.

## Constraints
- Do not increase PHP execution limits or switch the queue to sync.
- Do not process the batch inside the HTTP request.
- Preserve `PayrollContextLocker`, period state validation, authorization, company isolation, fingerprints, idempotency, unique constraints, `batch_item_id`, `resolution_hash`, `supersedes_id`, append-only decisions, audit projection, and partial overtime behavior.
- Do not parallelize candidates until lock keys and deadlock behavior are proven.
- Do not add indexes or use the projection for uploaded-file filtering without PostgreSQL and semantic evidence.
- Keep tests and documentation with each reviewable work-unit commit.

## Metrics
Record before/after for:
- child and parent Livewire action duration, query count, response bytes/effects, and render count;
- calls to `pendingTargetsForPeriod` and snapshot construction;
- batch insert duration and statement count;
- per-chunk processing time, continuation delay, total 249-item duration, and terminal totals;
- queue health/status/error observability;
- PostgreSQL query plans when evaluating projection or indexes.

Avoid brittle absolute-time CI assertions. Prefer bounded renders, scans, snapshots, query counts, inserts, state transitions, and deterministic continuation timing.

## Tasks
- [x] Create approved issue #396 and branch `perf/overtime-batch-flow` without touching `main`.
- [ ] Capture a reproducible baseline for request, transaction, insert, chunk, render, scan, and snapshot behavior.
- [ ] Separate batch acceptance events from terminal refresh and keep isolated progress mounted.
- [ ] Remove artificial normal continuation delay while preserving transient-error backoff.
- [ ] Reduce duplicate candidate resolution to one authoritative path.
- [ ] Shorten the requester critical transaction and evaluate safe bulk item insertion.
- [ ] Remove safe worker N+1 queries and validate lock-aware chunk processing.
- [ ] Improve progress/error/worker-stalled observability and loading target isolation.
- [ ] Evaluate SQL projection semantics for `uploaded_file_id` and prove index needs.
- [ ] Add functional and scale coverage for 249 candidates plus documented 500/501 boundaries.
- [ ] Run related SQLite/PostgreSQL suites, manual QA, review each work unit, and open the linked PR.

## Decisions
- The domain batch tables remain the source of truth even if Laravel `Bus::batch()` is evaluated later.
- Start serial and remove idle time before considering 2–4 controlled workers.
- A batch is accepted when durable rows commit; overtime decisions are recorded only when items reach terminal processing.
- Current follow-up commits are retained as prerequisite evidence rather than reimplemented.

## Verification
Pending baseline and tracer-bullet implementation.

## Work-unit commits
- Prerequisite local follow-up commits are documented in the inherited ODD files.
- New initiative commits: pending.

## QA plan
1. Load a representative payroll and open Review.
2. Filter overtime and select all 249 candidates.
3. Approve/reject, provide a reason, and confirm.
4. Verify the modal closes promptly and an accepted/queued message appears.
5. Verify only the progress component polls and counters advance to 249 terminal items.
6. Refresh, leave/return, stop/restart the worker, and verify recovery/status feedback.
7. Verify decisions, batch items, audit entries, uniqueness, and period integrity.
8. Exercise double click, invalid candidate, partial failure, 500 candidates, 501 candidates, approval, and rejection.

## Results
Pending.
