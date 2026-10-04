# Overtime batch submit renderless follow-up

## Goal
Make the `Confirmar lote` Livewire request return immediately after validating and queueing a batch, without rendering the expensive parent payroll review component.

## Evidence
- The batch request is queued by `OvertimeDecisionBatchRequester` and processed by `ProcessOvertimeDecisionBatch` in chunks of 20.
- `Revisar::requestOvertimeBatchFromPanel` currently mutates the active batch and then renders the whole parent component.
- The parent `Revisar` mount/render was measured at approximately 24.64 seconds on the realistic period with 1,191 marks.
- The user reports that confirming a 149-record batch remains stuck at the loading state; this is consistent with the parent render consuming most of the request budget after the batch has already been queued.

## Scope
- Make the parent batch request handler renderless.
- Keep validation, authorization, idempotency, batch creation, and queue dispatch unchanged.
- Keep progress visible by rendering a lightweight progress child shell and notifying it of the new batch ID.
- Preserve terminal refresh behavior and the overtime panel refresh.
- Do not change PHP execution limits or payroll blocking rules.

## Tasks
- [x] Add a renderless request path and an always-mounted progress listener.
- [x] Add focused regression coverage for renderless submission and progress notification.
- [x] Run focused tests, formatting, and diff checks.
- [x] Commit the work unit and record evidence.

## Verification
- `OvertimeBatchProgressTest.php`: 2 tests, 11 assertions.
- `RevisarTest.php` batch request regression: 1 test, 7 assertions.
- `RevisarTest.php`: 18 tests, 133 assertions.
- `PayrollDecisionLoadingFeedbackTest.php`: 1 test, 17 assertions.
- `OvertimeDecisionBatchRequesterTest.php`: 54 tests, 221 assertions.
- Pint and `git diff --check` passed.
- The request response has no parent HTML effect; it queues the batch and dispatches progress separately.

## Commits
- `e619c0d perf(nomina): return batch submissions immediately`
