# Overtime approval timeout follow-up

## Goal
Remove the remaining full readiness recomputation from the renderless single overtime decision event and prevent the initial review page from combining the expensive parent and overtime-panel renders in one request.

## Evidence
- After the renderless fix was merged, the latest log still recorded `Maximum execution time of 30 seconds exceeded` at CarbonInterval.php:1006 on 2026-10-03 17:53:56.
- `saveOvertimeDecisionFromPanel` remains renderless but still calls `loadReadinessBlockers()`, which measured about 6.98 seconds on the realistic period and cannot update parent HTML in a renderless response.
- The child panel already receives `overtime-decision-recorded` and refreshes its own targeted view.
- Current read-only timing after the follow-up: parent `Revisar` mount/render ~24.64s, child overtime panel ~8.34s, and child refresh ~7.98s. The parent + child initial page can exceed the 30s request limit when combined.

## Scope
- Stop recomputing parent readiness blockers inside the renderless single-decision event.
- Preserve recording, locking, validation, authorization, audit, child refresh, and success behavior.
- Add focused regression evidence that the parent handler does not invoke readiness recomputation.
- Keep cache clearing and PHP limits separate from source behavior.
- Lazy-load the overtime panel after the parent response so the initial request does not combine both expensive renders.

## Tasks
- [x] Remove the wasted readiness recomputation and add regression coverage.
- [x] Verify focused tests and formatting.
- [x] Commit and provide retest instructions.
- [x] Add lazy loading for the overtime panel and verify the initial request boundary.

## Verification
- Passed focused single-decision test: 1 test, 6 assertions.
- Passed complete `tests/Feature/Nomina/RevisarTest.php`: 17 tests, 126 assertions.
- Passed Pint `--test` for `Revisar.php` and `RevisarTest.php`.
- Passed `git diff --check`.
- Regression confirms no readiness blocker recomputation, decision persistence, targeted child event, and no HTML render effect.
- Passed `tests/Feature/Ui/PayrollDecisionLoadingFeedbackTest.php`: 1 test, 15 assertions, including `lazy="on-load"` and preserved panel props.
- Passed `tests/Feature/Nomina/RevisarTest.php`: 17 tests, 126 assertions after adapting the parent/child lazy-render boundary.
- Parent/child timing evidence: ~24.64s parent and ~8.34s child are now separated into distinct requests.

## Commits
- `677c8ce fix(nomina): skip readiness scan after overtime decision`
- `cfb5a7f perf(nomina): lazy-load overtime review panel`
