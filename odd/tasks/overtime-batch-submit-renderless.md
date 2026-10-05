# Overtime batch submit renderless follow-up

## Goal
Make the `Confirmar lote` child dispatch and parent queue request return without rendering either expensive payroll review component.

## Evidence
- The batch request is queued by `OvertimeDecisionBatchRequester` and processed by `ProcessOvertimeDecisionBatch` in chunks of 20.
- Before the parent renderless change, `Revisar::requestOvertimeBatchFromPanel` mutated the active batch and then rendered the whole parent component.
- The parent `Revisar` mount/render was measured at approximately 24.64 seconds on the realistic period with 1,191 marks.
- The user reports that confirming an all-filtered batch remains stuck at the loading state.
- Exact local reproduction found the request is rejected before batch creation: the child intentionally sends `all: true` with `selected: []`, while the parent validates `selected` as `required|array`. The renderless parent response hides that validation error.
- On the exact PayPeriod 35 profile, the child `submitOvertimeBatch` request took approximately 22.8 seconds because it recomputed every target and then rendered the full panel.
- On the same profile, the separately authoritative parent all-filtered action took approximately 17.2 seconds while creating every item, verifying targets and the stored hash, queueing the batch, and returning without a render effect.

## Scope
- Make successful child and parent batch request handlers renderless.
- Let the child dispatch the modal's validated stored intent without recomputing targets; keep authoritative target and selection-hash validation in the parent.
- Keep validation, authorization, idempotency, batch creation, and queue dispatch unchanged.
- Keep progress visible by rendering a lightweight progress child shell and notifying it of the new batch ID.
- Preserve terminal refresh behavior and the overtime panel refresh.
- Do not change PHP execution limits or payroll blocking rules.

## Tasks
- [x] Add a renderless request path and an always-mounted progress listener.
- [x] Add focused regression coverage for renderless submission and progress notification.
- [x] Run focused tests, formatting, and diff checks.
- [x] Commit the renderless work unit and record evidence.
- [x] Accept an empty explicit selection when `all` is true and add regression coverage.
- [x] Dispatch the validated stored intent without a child target rescan or successful-response render.
- [x] Preserve rendered child validation errors and parent rejection of a non-empty stale selection hash.
- [x] Re-measure the corrected all-filtered request path on PayPeriod 35 in a separate profiling unit.

## Verification
- `OvertimeBatchProgressTest.php`: 2 tests, 11 assertions.
- `RevisarTest.php` batch request regression: 1 test, 7 assertions.
- RED — `php artisan test tests/Feature/Nomina/RevisarTest.php --filter='all-filtered batch accepts an empty explicit selection without rendering the payroll review'`: 1 failed because the component had a `selected` validation error.
- GREEN — the same focused command: 1 test passed, 13 assertions, including the `all: false` empty-selection rejection path.
- `PayrollDecisionLoadingFeedbackTest.php`: 1 test, 17 assertions.
- RED — `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='validated modal submission dispatches its stored batch intent without rendering'`: 2 tests failed, 22 assertions; the child detected recomputed candidate drift and returned a `selectedOvertimeCandidates` error.
- GREEN — the same focused command: 2 tests passed, 32 assertions; validation errors retained an HTML effect while each successful dispatch had none.
- `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php --filter='parent rejects a non-empty selection when its stored hash is stale'`: 1 test passed, 6 assertions.
- `php artisan test tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php`: 55 tests passed, 221 assertions.
- `php artisan test tests/Feature/Nomina/RevisarTest.php`: 19 tests passed, 146 assertions.
- `./vendor/bin/pint app/Livewire/Nomina/OvertimeReviewPanel.php tests/Feature/Attendance/OvertimeDecisionBatchRequesterTest.php tests/Feature/Nomina/RevisarTest.php` passed.
- `git diff --check` passed.
- Successful child and parent request responses have no HTML effect; the parent remains authoritative for target and selection-hash validation before queueing.
- PayPeriod 35 re-measurement with 249 targets: successful child submit took 0.016s (down from ~22.8s), parent verification and temporary batch creation took 16.52s, and combined post-confirm time was 16.53s.
- The temporary batch contained all 249 pending items and dispatched both progress events; the outer transaction rollback restored batch/item counts to 6/118. Queue dispatch was intentionally not observed because it is registered with `DB::afterCommit` and the measurement transaction rolled back.

## Commits
- `e619c0d perf(nomina): return batch submissions immediately`
- `0936865 fix(nomina): accept all-filtered overtime batches`
