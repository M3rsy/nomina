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
- Partial Xdebug/query baseline after the real batch consumed every pending target: `Revisar` mount 15.06s/31 queries/1,387,442 HTML bytes; panel mount 7.45s/16 queries/6,231 bytes; select-all 7.36s/18 queries; open modal 15.07s/32 queries. The empty result path prevented a second successful acceptance measurement.
- Snapshot call evidence: initial parent and panel mounts each construct one period snapshot; opening the modal calls `pendingTargetsForPeriod` once and constructs two snapshots because the action scan is followed by a panel render scan.
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

### Worker baseline — 249 items
A reversible PostgreSQL scheduler harness used one synthetic batch with 249 items and a recorder test double, then rolled the transaction back:
- 13 handle invocations at chunk size 20;
- 2.348s total handle wall time and 2,023 queries;
- 12 observed successful continuations released with 10-second delays;
- 120s deterministic artificial idle and 122.348s scheduler total including idle;
- steady full chunk: 162 queries, including 20 repeated loads each for actor, batch, period, and employee;
- all 249 items ended succeeded with one attempt and the batch ended completed;
- synthetic bulk setup demonstrated 249 item rows can be inserted in one statement in 22.8ms on the local PostgreSQL dataset.
- current `createMany()` persistence was measured independently at 249 INSERT statements, 210.2ms wall time, and 83.42ms PostgreSQL-reported time for 249 items; the transaction rollback restored row counts.

The real local batch #10 provides end-to-end observational evidence: 249 items and 249 decisions succeeded, created/started at 16:45:18 and finished at 16:48:15 (177s). Removing the 120s scheduled idle implies about 57s of real processing/overhead in that run; this is observational, not an isolated recorder benchmark.

## Tasks
- [x] Create approved issue #396 and branch `perf/overtime-batch-flow` without touching `main`.
- [x] Capture a reproducible baseline for request, transaction, insert, chunk, render, scan, and snapshot behavior.
- [x] Separate batch acceptance events from terminal refresh and keep isolated progress mounted.
- [x] Remove artificial normal continuation delay while preserving transient-error backoff.
- [x] Reduce duplicate candidate resolution to one authoritative path.
- [ ] Shorten the requester critical transaction and evaluate safe bulk item insertion.
- [x] Remove safe worker N+1 queries and validate lock-aware chunk processing.
- [ ] Improve progress/error/worker-stalled observability and loading target isolation.
- [ ] Evaluate SQL projection semantics for `uploaded_file_id` and prove index needs.
- [ ] Add functional and scale coverage for 249 candidates plus documented 500/501 boundaries.
- [ ] Run related SQLite/PostgreSQL suites, manual QA, review each work unit, and open the linked PR.

## Decisions
- The domain batch tables remain the source of truth even if Laravel `Bus::batch()` is evaluated later.
- Start serial and remove idle time before considering 2–4 controlled workers.
- A batch is accepted when durable rows commit; overtime decisions are recorded only when items reach terminal processing.
- Current follow-up commits are retained as prerequisite evidence rather than reimplemented.
- Delivery uses stacked PRs to `main`, selected by the user after the running diff exceeded the 400-line review budget. Each slice must name its predecessor and remain independently reviewable.

## Verification
- Reversible local PostgreSQL measurements restored batch/item/decision row counts after each harness.
- `createMany()` baseline: 249 requested/unique rows, 249 INSERT statements, 210.2ms wall, 83.42ms database time.
- Worker baseline: 249/249 terminal successes, 13 chunks, 12 releases of 10s, 120s deterministic idle, 2,023 scheduler queries.
- Real batch #10: 249 items and decisions succeeded with no duplicates and terminal `completed` status in 177s.
- No source changes were made during baseline capture; only this ODD evidence document changed. PostgreSQL sequences are nontransactional and advanced during preliminary/reversible harness attempts; persisted row counts and content remained intact.
- RED: `php artisan test tests/Feature/Nomina/RevisarTest.php --filter='batch request accepts durable work without claiming decisions are recorded'` failed because `overtime-batch-accepted` was not dispatched.
- RED: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='accepted batches close their modal while recorded batches refresh terminal data'` failed because the accepted-event handler did not exist.
- RED: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='refreshes the panel once only after the exact active batch is verified terminal without rendering the parent'` failed because the parent response contained an HTML effect.
- GREEN: the same three focused commands passed with 8, 12, and 9 assertions respectively.
- Triangulation: `php artisan test tests/Feature/Nomina/RevisarTest.php --filter='all-filtered batch accepts an empty explicit selection without rendering the payroll review'` passed with 14 assertions.
- `php artisan test tests/Feature/Nomina/RevisarTest.php` passed: 19 tests, 148 assertions.
- `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed: 56 tests, 241 assertions.
- `vendor/bin/pint app/Livewire/Nomina/Revisar.php app/Livewire/Nomina/OvertimeReviewPanel.php tests/Feature/Nomina/RevisarTest.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed.
- `git diff --check` passed with no output.
- RED: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='immediately releases the same queued job between bounded chunks until terminal'` failed because the successful first chunk released with 10 seconds instead of the expected zero seconds (1 failed test, 2 assertions before failure).
- GREEN: the same focused command passed after normal continuation changed to `release(0)` (1 test, 10 assertions); the job's public retry backoff remained 10 seconds.
- Triangulation: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='reports deterministic progress across three immediate chunks with a validation failure'` passed (1 test, 29 assertions). Three explicit invocations reported 20, 40, and 41 completed items; the terminal state had 40 successes, 1 validation failure, no pending or processing items, 100% progress, zero remaining, `completed_with_errors`, and a non-null `finished_at`.
- `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed: 57 tests, 271 assertions.
- `vendor/bin/pint app/Jobs/ProcessOvertimeDecisionBatch.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed.
- Current work unit: `git diff --check` passed with no output.
- RED worker query regression: a 20-item SQLite chunk executed 165 queries with 41 batch selects and 20 each for actor, period, and employee.
- GREEN worker query regression: the same chunk executed 32 queries with 3 batch selects, one actor select, one period select, and one employee `whereIn` select; recovery still prioritizes processing items.
- PostgreSQL 249-item verification at `ef85bef`: 13 chunks, 369 scheduler queries (81.8% / 1,654 fewer than the 2,023-query baseline), 392.6ms handle wall time, 12 immediate zero-second releases, zero artificial idle, 249 unique successful item calls, and terminal completed state. Rollback restored 7 batches/367 items/367 decisions and did not advance sequences.
- Worker scheduler RED: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='loads scheduler invariants once for a full worker chunk'` failed with 165 queries. Normalized SELECT categories were `batch.select=41`, `actor.select=20`, `period.select=20`, `employee.select=20`, and `employee.where_in=0`.
- Worker scheduler GREEN: the same focused command passed with 32 queries. Normalized SELECT categories were `batch.select=3`, `actor.select=1`, `period.select=1`, `employee.select=1`, and `employee.where_in=1`.
- Recovery triangulation: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='claims a recovered processing item before pending work in the next chunk'` passed with 9 assertions; the pre-existing processing item ran first, its attempts increased from 2 to 3, 20 items succeeded, and one remained pending for immediate continuation.
- Lifecycle triangulation covering revoked authorization, infrastructure failure, bounded continuation, and a validation failure passed: 4 tests, 49 assertions.
- `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed: 59 tests, 283 assertions.
- `vendor/bin/pint app/Jobs/ProcessOvertimeDecisionBatch.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed.
- RED selection-resolution regression: `php artisan test tests/Feature/Nomina/RevisarTest.php --filter='batch request accepts durable work without claiming decisions are recorded'` failed because the submit action captured raw marks twice instead of once (1 failed test, 8 assertions before failure).
- GREEN selection-resolution regression: the same focused command passed with 9 assertions after the parent stopped resolving targets and the requester became the sole authoritative resolver.
- Selection/idempotency triangulation: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='applies explicit selection after one authoritative filtered resolution|keeps all-match selection within its uploaded file scope|recovers an exact idempotent retry after its candidates were processed without rescanning|binds every selection input into the idempotency payload before rescanning'` passed: 4 tests, 21 assertions. A new request performs one filtered resolution; an exact retry after processing performs zero raw-mark captures; changed period, actor, decision, reason, uploaded-file scope, filters, all flag, selected tokens, or expected selection hash is rejected before rescanning.
- Parent validation triangulation: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='parent rejects malformed or unexpected batch intent fields before resolution'` passed: 2 tests, 6 assertions.
- `php artisan test tests/Feature/Nomina/RevisarTest.php` passed: 19 tests, 149 assertions.
- `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed: 64 tests, 308 assertions.
- `vendor/bin/pint app/Livewire/Nomina/Revisar.php app/Services/Attendance/OvertimeDecisionBatchRequester.php app/Services/Attendance/OvertimeDecisionBatchRequest.php tests/Feature/Nomina/RevisarTest.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed.

## Work-unit commits
- Prerequisite local follow-up commits are documented in the inherited ODD files.
- `fae820b docs(odd): plan overtime batch performance`
- `a81899e docs(odd): record overtime batch baseline`
- `16e7f3b docs(odd): link overtime baseline work unit`
- `37ce605 refactor(nomina): separate batch acceptance from completion`
- `34a14ca docs(odd): link batch lifecycle work unit`
- `e23adc4 perf(nomina): remove idle delay between overtime chunks`
- `c576f3b docs(odd): link chunk continuation work unit`
- `ef85bef perf(queue): preload overtime batch chunk context`

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
- Issue #396 is approved and tracks the complete outcome.
- Baseline confirms normal continuation delay, per-item invariant N+1 loads, opt-in worker risk, long requester lock scope, upload-filter projection incompatibility, and acceptance/terminal event ambiguity.
- Durable enqueue now emits `overtime-batch-accepted` to the review panel and preserves `overtime-batch-started` for isolated progress without emitting the terminal `overtime-batch-recorded` event.
- The accepted event closes and clears batch modal/selection state; `overtime-batch-recorded` remains the terminal panel refresh event.
- The parent terminal listener verifies the exact active actor-scoped batch, emits the terminal event once, and produces no HTML effect.
- Successful non-terminal chunks now release immediately, while the queue job retains its public 10-second backoff for exception-driven retries.
- Each worker invocation claims a bounded chunk transactionally, loads batch/actor/period once, loads employees with one `whereIn`, and leaves recorder/PayrollContextLocker revalidation authoritative per item.
- The worker now claims and increments up to 20 items in one batch-row transaction, preserving processing-before-pending recovery order. It then loads batch, actor, and period once and all unique employees in one `whereIn` query; recorder-level batch/actor revalidation and payroll locking remain authoritative.
- The deterministic SQLite `handle()` seam regression reduced a full mocked-recorder chunk from 165 to 32 queries, with batch SELECTs reduced from 41 to 3 and actor, period, and employee SELECTs reduced from 20 each to 1 each.
- `OvertimeDecisionBatchRequester` now owns the authoritative filtered pending-target resolution through a typed request value; the parent validates only the untrusted intent shape and no longer scans candidates or computes confirmation hashes.
- The public renderless submit action now captures raw marks once instead of twice and preserves accepted/started event semantics without an HTML effect.
- Idempotency payload validation remains ahead of candidate resolution. Exact retries after completed decisions recover with zero raw-mark captures, while every selection input is bound to the stored payload hash.
