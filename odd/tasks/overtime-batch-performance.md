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

## Accepted-modal follow-up diagnosis
Real batch #14 processed 249/249 items successfully in 13 chunks. During its 2m53s window, 58 Livewire updates completed with HTTP 200 responses at a 72ms median, plus one 14.253s outlier compatible with a heavyweight `OvertimeReviewPanel` render. This is diagnosis evidence, not causal proof: the existing tests cover the event descriptor and isolated PHP state, not real multi-component browser DOM timing.

The installed Livewire client proves targeted events do not bubble: `vendor/livewire/livewire/dist/livewire.esm.js:9250-9254` dispatches to `target.el` with the bubbling argument set to `false`, and `vendor/livewire/livewire/dist/livewire.esm.js:9272-9277` creates the event with `{ bubbles: false }`. Therefore a modal-local `x-on:overtime-batch-accepted.window` listener cannot receive the parent event targeted to `OvertimeReviewPanel`. The component root must catch that event directly and bridge it to a distinct bubbling Alpine event for the modal-local window latch.

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
- [x] Shorten the requester critical transaction and evaluate safe bulk item insertion; retain the authoritative scan under the period lock until every candidate dependency has a shared revision contract.
- [x] Remove safe worker N+1 queries and validate lock-aware chunk processing.
- [x] Improve progress/error/worker-stalled observability and loading target isolation.
- [x] Close the accepted batch modal without triggering a heavyweight panel render; preserve rejection visibility.
- [x] Evaluate SQL projection semantics for `uploaded_file_id` and prove index needs; retain canonical mixed-file resolution and make no unsupported index change.
- [x] Add functional and scale coverage for 249 candidates plus documented 500/501 boundaries.
- [x] Run related automated suites, review each work unit, push the stack, and open the linked PRs.
- [ ] Complete the user-deferred post-modal manual QA, then retarget and merge the stack in order.

## Decisions
- The domain batch tables remain the source of truth even if Laravel `Bus::batch()` is evaluated later.
- Start serial and remove idle time before considering 2–4 controlled workers.
- A batch is accepted when durable rows commit; overtime decisions are recorded only when items reach terminal processing.
- Current follow-up commits are retained as prerequisite evidence rather than reimplemented.
- Delivery uses stacked PRs to `main`, selected by the user after the running diff exceeded the 400-line review budget. Each slice names its predecessor and remains independently reviewable.
- The user explicitly authorized `size:exception` for PR #401 at 494 changed lines because its typed request, requester migration, parent integration, and regression tests form one atomic API change; every other slice is at or below 286 changed lines.
- Keep `payroll_review_entries` as a UI/cache source, not the authoritative batch-request source. Its generation/build/read steps are not one atomic revision and omit snapshot dependencies such as fact generations and vacation inputs.
- Do not project `uploaded_file_id` by candidate ownership. Current semantics retain an entire shift occurrence when any contributing mark came from the file, including every candidate in a mixed-file occurrence. Exact projection support would require generation-owned occurrence/upload membership (for example, an entry-to-upload link table) plus complete freshness coverage.
- Add or remove no PostgreSQL index for this issue: measured snapshot SELECTs are milliseconds while candidate evaluation is seconds. The standalone `raw_marks(uploaded_file_id)` index may be structurally redundant with the unique `(uploaded_file_id, row_number)` prefix, but its cleanup is unrelated and unproven here.
- Treat 30 seconds without batch or item activity as an observability heuristic only. The payload distinguishes queued and processing delay reasons, keeps the durable batch status authoritative, and continues polling every nonterminal batch.
- The 249-item regression characterizes already-implemented scale behavior. A meaningful RED is not expected; the first focused execution must establish GREEN evidence without production changes or wall-time thresholds.

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
- RED 500-candidate requester regression: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='durably requests 500 candidates with one item insert and hydrates them after commit'` failed because the query listener observed 500 item-table INSERT statements instead of one; the durable/default/fingerprint assertions preceding the query-count assertion passed.
- GREEN 500-candidate requester regression: the same focused command passed with 15 assertions. It observed one item-table INSERT, 500 durable readable items with `pending` status, zero attempts, set timestamps, matching fingerprints independent of row order, and the first relation SELECT at the pre-request transaction level.
- 501-candidate boundary triangulation: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='rejects more than 500 filtered overtime matches without creating a batch'` passed with 7 assertions and no batch row.
- Requester suite: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed with 65 tests and 323 assertions.
- `vendor/bin/pint app/Services/Attendance/OvertimeDecisionBatchRequester.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed.
- Reversible PostgreSQL 249-candidate verification reduced item persistence from 249 INSERTs to one. Requester wall time was 50.564ms versus the 210.2ms `createMany()` baseline; item INSERT listener time was 15.370ms versus 83.42ms. The insert ran at the nested requester transaction level and relation hydration ran only after returning to the outer baseline transaction. All 249 rows were pending with zero attempts, matching fingerprints, and timestamps. Rollback restored 7 batches, 367 items, 367 decisions, zero jobs, and both affected sequence states exactly.
- Read-only PostgreSQL period-35 evidence located the remaining canonical-read cost in PHP: 6,670.55ms wall and 15 queries/33.34ms DB time, with `forEachReview` alone taking 6,513.73ms and zero queries while producing 552 reviews, 249 overtime candidates, and 164 deficits. Five representative snapshot SELECTs totaled 2.887ms. Raw marks used bitmap scans on existing company and event-time indexes (1,191 rows, 2.173ms); decisions used rational sequential scans/hash anti-join over 367 rows (349 returned, 0.601ms). No index change is justified for the measured 7–16s path.
- Progress observability RED: `php artisan test tests/Feature/Nomina/OvertimeBatchProgressTest.php` failed 5 new tests because lifecycle timestamps, latest activity, delay metadata, and batch `last_error` were absent.
- Loading-target RED: `php artisan test tests/Feature/Ui/PayrollDecisionLoadingFeedbackTest.php` failed because approved/rejected open actions and batch cancellation were not scoped to their intended Livewire methods.
- Progress observability GREEN: `php artisan test tests/Feature/Nomina/OvertimeBatchProgressTest.php` passed with 8 tests and 52 assertions, including once-only terminal notification, non-duplicated batch-error feedback, and grouped-query latest-activity coverage.
- Loading-target GREEN: `php artisan test tests/Feature/Ui/PayrollDecisionLoadingFeedbackTest.php` passed with 1 test and 21 assertions.
- Progress integration triangulation: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='reports deterministic progress|renders actor scoped batch progress|stops isolated polling'` passed with 3 tests and 47 assertions.
- `vendor/bin/pint --test app/Livewire/Nomina/OvertimeBatchProgress.php resources/views/livewire/nomina/overtime-batch-progress.blade.php resources/views/livewire/nomina/overtime-review-panel.blade.php tests/Feature/Nomina/OvertimeBatchProgressTest.php tests/Feature/Ui/PayrollDecisionLoadingFeedbackTest.php` passed after fixing test import ordering.
- Scale characterization RED: not applicable because the optimized 249-item behavior was already implemented; no production change was permitted or needed. The first focused execution was GREEN: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='processes 249 synthetic candidates in 13 immediate chunks with bounded invariant selects'` passed in 1.07s with 60 assertions.
- The focused 249-item verification rerun passed in 1.04s with 61 assertions. It observed 369 scheduler queries under an upper bound of 369 and asserted category counts of 39 batch SELECTs plus 13 actor, 13 period, and 13 employee `where in` SELECTs across 13 chunks. It also proved 12 zero-second releases, a non-released terminal invocation, 249 unique successful recorder calls, one attempt per item, terminal progress at 249/249 and 100%, stopped polling, and once-only terminal notification.
- `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='durably requests 500 candidates with one item insert and hydrates them after commit|rejects more than 500 filtered overtime matches without creating a batch'` passed in 1.15s: 2 tests, 22 assertions. The exact 500 acceptance/one-INSERT and 501 rejection boundaries remain covered through the shared synthetic-review helper.
- `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed in 39.85s: 66 tests, 383 assertions.
- `vendor/bin/pint tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed.
- `git diff --check` passed with no output; the pre-existing `package-lock.json` modification remains untouched.
- Accepted-modal RED: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='accepted batches close their modal while recorded batches refresh terminal data'` failed because the accepted listener response still contained an HTML effect after all server modal fields and selection state were reset (1 failed test, 9 assertions before failure).
- Accepted-modal GREEN: the same focused command passed after making only `acceptOvertimeBatch()` renderless (1 test, 15 assertions). The recorded/terminal listener remained renderful.
- Rejection triangulation: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='rejected batches keep the modal open and render their validation message'` passed (1 test, 7 assertions); the modal remained open, exposed the validation message, and returned an HTML effect.
- Initial structural/loading coverage: `php artisan test tests/Feature/Ui/PayrollDecisionLoadingFeedbackTest.php` passed (1 test, 23 assertions), but it incorrectly treated the targeted accepted event as observable at `window`; installed Livewire non-bubbling behavior invalidated that assertion.
- Requester suite: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed (67 tests, 394 assertions).
- `vendor/bin/pint app/Livewire/Nomina/OvertimeReviewPanel.php resources/views/livewire/nomina/overtime-review-panel.blade.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php tests/Feature/Ui/PayrollDecisionLoadingFeedbackTest.php` passed.
- Accepted-modal follow-up: `git diff --check` passed with no output; the unrelated `package-lock.json` modification remains untouched.
- Event-bridge RED: `php artisan test tests/Feature/Ui/PayrollDecisionLoadingFeedbackTest.php` failed after requiring the component root to catch `overtime-batch-accepted` directly and dispatch a distinct `overtime-batch-modal-close` event; the old markup only had the unreachable accepted-event window listener (1 failed test, 11 assertions before failure).
- Event-bridge GREEN: the same UI command passed (1 test, 25 assertions) after adding both bridge halves and excluding accepted/rejected window listeners.
- Alpine-root activation RED/GREEN: the UI command then failed while requiring `x-data` on the component root (1 failed test, 11 assertions before failure), and passed again (1 test, 25 assertions) after the root became an Alpine scope for its direct event listener.
- Event-bridge acceptance/rejection checks passed independently: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='accepted batches close their modal while recorded batches refresh terminal data'` passed (1 test, 15 assertions), and `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='rejected batches keep the modal open and render their validation message'` passed (1 test, 7 assertions).
- Event-bridge requester suite: `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php` passed (67 tests, 394 assertions).
- `vendor/bin/pint resources/views/livewire/nomina/overtime-review-panel.blade.php tests/Feature/Ui/PayrollDecisionLoadingFeedbackTest.php` passed.
- Event-bridge correction: `git diff --check` passed with no output; the unrelated `package-lock.json` modification remains untouched.
- Independent event-bridge verification confirmed Livewire 3.8.2 targets the component root with `bubbles=false`, Alpine catches that event directly on the root, and the distinct close event bubbles to the modal-local window listener. Focused acceptance/rejection passed with 2 tests and 22 assertions; UI structure passed with 1 test and 25 assertions; the full requester suite passed with 67 tests and 394 assertions; focused Pint, LSP diagnostics, and `git diff --check` passed.
- Live GitHub delivery verification confirmed issue #396 is open with `status:approved`, no prior PR for the issue existed, all nine branch names were free, and the unrelated local `package-lock.json` change remained excluded.
- PR #397 targets `main`; PRs #398–#405 are drafts with clean predecessor-branch diffs. The repository workflow runs only for `main`/`master` and selected legacy bases, so each draft must be retargeted to `main` after its predecessor merges before checks/review. At publication, #397 PostgreSQL had passed and Pest was still running.

## Pull request stack
| Position | PR | Base | Head | Changed lines | Labels | State |
| --- | --- | --- | --- | ---: | --- | --- |
| 1 | #397 | `main` | `perf/overtime-request-latency` | 286 | `type:feature` | Ready |
| 2 | #398 | `perf/overtime-request-latency` | `fix/overtime-all-filtered-selection` | 211 | `type:bug` | Draft |
| 3 | #399 | `fix/overtime-all-filtered-selection` | `refactor/overtime-batch-lifecycle` | 282 | `type:refactor` | Draft |
| 4 | #400 | `refactor/overtime-batch-lifecycle` | `perf/overtime-worker-context` | 160 | `type:feature` | Draft |
| 5 | #401 | `perf/overtime-worker-context` | `perf/overtime-single-resolution` | 494 | `type:feature`, `size:exception` | Draft |
| 6 | #402 | `perf/overtime-single-resolution` | `perf/overtime-bulk-insert` | 97 | `type:feature` | Draft |
| 7 | #403 | `perf/overtime-bulk-insert` | `feat/overtime-batch-observability` | 286 | `type:feature` | Draft |
| 8 | #404 | `feat/overtime-batch-observability` | `test/overtime-batch-scale` | 194 | `type:chore` | Draft |
| 9 | #405 | `test/overtime-batch-scale` | `fix/overtime-accepted-modal` | 83 | `type:bug` | Draft |

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
- `2a0a921 docs(odd): record worker query improvement`
- `105519d perf(attendance): resolve overtime batch selection once`
- `b36dd69 docs(odd): link selection resolution work unit`
- `106764f perf(attendance): bulk insert overtime batch items`
- `8fc16d1 docs(odd): link batch insert work unit`
- `ec087d5 docs(odd): record projection and index evidence`
- `d209c51 docs(odd): link projection evidence work unit`
- `cda04ff feat(nomina): surface overtime batch activity`
- `ee538d1 docs(odd): link batch activity work unit`
- `57241e8 test(attendance): cover overtime batch scale`
- `c9a88c4 docs(odd): link batch scale work unit`
- `92ba44d fix(nomina): close accepted batch modal`
- `59b4477 docs(odd): link accepted modal work unit`
- `43b8ff3 docs(odd): record stacked pull requests`

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
- Issue #396 is approved and tracks the complete outcome; PRs #397–#405 publish the nine-slice stack without merging it.
- Baseline confirms normal continuation delay, per-item invariant N+1 loads, opt-in worker risk, long requester lock scope, upload-filter projection incompatibility, and acceptance/terminal event ambiguity.
- Durable enqueue now emits `overtime-batch-accepted` to the review panel and preserves `overtime-batch-started` for isolated progress without emitting the terminal `overtime-batch-recorded` event.
- The accepted event closes and clears batch modal/selection state; `overtime-batch-recorded` remains the terminal panel refresh event.
- The parent terminal listener verifies the exact active actor-scoped batch, emits the terminal event once, and produces no HTML effect.
- Successful non-terminal chunks now release immediately, while the queue job retains its public 10-second backoff for exception-driven retries.
- Each worker invocation claims a bounded chunk transactionally, loads batch/actor/period once, loads employees with one `whereIn`, and leaves recorder/PayrollContextLocker revalidation authoritative per item.
- The worker now claims and increments up to 20 items in one batch-row transaction, preserving processing-before-pending recovery order. It then loads batch, actor, and period once and all unique employees in one `whereIn` query; recorder-level batch/actor revalidation and payroll locking remain authoritative.
- The deterministic SQLite `handle()` seam regression reduced a full mocked-recorder chunk from 165 to 32 queries, with batch SELECTs reduced from 41 to 3 and actor, period, and employee SELECTs reduced from 20 each to 1 each.
- The deterministic test-only 249-item scale regression uses one synthetic review and the public requester, then drives 13 manual mocked-recorder chunks. It preserves the one acceptance dispatch, creates no decisions, releases only the 12 nonterminal chunks at zero seconds, and confirms invariant SELECTs scale with chunks rather than items.
- `OvertimeDecisionBatchRequester` now owns the authoritative filtered pending-target resolution through a typed request value; the parent validates only the untrusted intent shape and no longer scans candidates or computes confirmation hashes.
- The public renderless submit action now captures raw marks once instead of twice and preserves accepted/started event semantics without an HTML effect.
- Overtime progress now exposes lifecycle timestamps, latest batch/item activity, batch errors, and queued-versus-processing delay reasons. Spanish UI labels and warnings remain truthful: inactivity suggests checking the queue worker if unchanged but does not claim it stopped.
- Batch approve/reject open controls target only their exact action; confirmation and cancellation target only `submitOvertimeBatch`, while selection controls remain untargeted.
- Durable acceptance now resets modal and selection state in a renderless panel listener. The Alpine-scoped panel root catches Livewire's targeted, non-bubbling accepted event directly and dispatches the distinct bubbling `overtime-batch-modal-close` event; the modal-local Alpine latch closes from that bridge event at window scope. Rejection remains renderful, keeps the modal open, and displays the server validation message without touching the close latch.
- Idempotency payload validation remains ahead of candidate resolution. Exact retries after completed decisions recover with zero raw-mark captures, while every selection input is bound to the stored payload hash.
