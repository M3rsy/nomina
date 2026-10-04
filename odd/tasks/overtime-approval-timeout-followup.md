# Overtime approval timeout follow-up

## Goal
Remove the remaining full readiness recomputation from the renderless single overtime decision event.

## Evidence
- After the renderless fix was merged, the latest log still recorded `Maximum execution time of 30 seconds exceeded` at CarbonInterval.php:1006 on 2026-10-03 17:53:56.
- `saveOvertimeDecisionFromPanel` remains renderless but still calls `loadReadinessBlockers()`, which measured about 6.98 seconds on the realistic period and cannot update parent HTML in a renderless response.
- The child panel already receives `overtime-decision-recorded` and refreshes its own targeted view.

## Scope
- Stop recomputing parent readiness blockers inside the renderless single-decision event.
- Preserve recording, locking, validation, authorization, audit, child refresh, and success behavior.
- Add focused regression evidence that the parent handler does not invoke readiness recomputation.
- Keep cache clearing and PHP limits separate from source behavior.

## Tasks
- [x] Remove the wasted readiness recomputation and add regression coverage.
- [x] Verify focused tests and formatting.
- [ ] Commit and provide retest instructions.

## Verification
- Passed focused single-decision test: 1 test, 6 assertions.
- Passed complete `tests/Feature/Nomina/RevisarTest.php`: 17 tests, 126 assertions.
- Passed Pint `--test` for `Revisar.php` and `RevisarTest.php`.
- Passed `git diff --check`.
- Regression confirms no readiness blocker recomputation, decision persistence, targeted child event, and no HTML render effect.

## Commits
- Pending.
