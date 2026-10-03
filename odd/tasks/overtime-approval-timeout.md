# Overtime approval timeout

## Goal
Prevent single overtime decisions from returning HTTP 500 after exceeding PHP's 30-second execution limit on realistic payroll periods.

## Root cause evidence
- `storage/logs/laravel.log` records `Maximum execution time of 30 seconds exceeded` at Carbon internals on 2026-10-03 16:56:48 and 17:02:27.
- Local period 35 (company 4, 66 employees, 1,191 marks) takes about 21.9 seconds for a full `Revisar` render, 6.9 seconds for the overtime panel, and 6.98 seconds to recompute readiness blockers.
- The overtime event handler calls `loadReadinessBlockers()` and then causes a full parent render, while the child panel refreshes separately; the simulated post-decision path takes about 27.9 seconds before production variance.
- Focused overtime tests pass, so the regression is dataset-scale request composition rather than decision validity.

## Scope
- Make the single overtime decision event renderless so the parent does not recompute the entire review page in the same request.
- Preserve decision recording, audit behavior, child-panel refresh event, validation, authorization, and readiness behavior for the next normal page interaction.
- Add a regression test proving the event response skips HTML rendering while still dispatching the child refresh event.
- Do not change PHP execution limits or bypass payroll locking.
- Keep the pre-existing `package-lock.json` modification untouched.

## Tasks
- [x] Add a renderless single overtime decision handler and focused regression test.
- [x] Run focused overtime tests, formatting, and performance checks.
- [x] Record the work-unit commit and close with user-facing QA instructions.

## Decisions
- Use Livewire's renderless action boundary rather than increasing `max_execution_time` or removing readiness checks.
- The child overtime panel remains responsible for its targeted refresh; the parent readiness snapshot is refreshed on the next normal parent interaction.

## Verification
- Passed focused overtime tests: 2 tests, 24 assertions.
- Passed `tests/Feature/Nomina/OvertimeApprovalPerformanceTest.php`: 1 test, 6 assertions.
- Passed Pint `--test` for `Revisar.php` and `RevisarTest.php`.
- Passed `git diff --check` for changed files.
- Regression confirms the parent action dispatches `overtime-decision-recorded` without an HTML render effect.
- The period-35 wall-clock harness was not rerun after the fix; browser QA remains required for the child-to-parent-to-child cycle.

## Commits
- `4dc866d fix(nomina): avoid overtime approval render timeout`
