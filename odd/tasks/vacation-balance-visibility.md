# Vacation balance visibility

## Goal
Make manual vacation balance adjustments immediately visible and auditable in `/vacaciones`, independently of whether an employee already has a vacation record.

## Scope
- Add an employee-based balance ledger that does not depend on `vacations` rows.
- Add a recent append-only balance movement history with employee, delta, reason, actor, type, and timestamp.
- Show explicit success feedback after a manual balance adjustment.
- Preserve tenant scoping, authorization, existing vacation filters/pagination, append-only movements, and vacation calculations.

## Non-goals
- Editing or deleting balance movements.
- Changing vacation balance calculations or persistence contracts.
- Adding adjustment idempotency or altering historical data.

## Tasks
- [x] 1. Add a failing Livewire regression test proving an adjusted employee without vacations appears with the new balance, then implement the employee balance ledger.
- [x] 2. Add a failing presentation test for movement audit details and success feedback, then implement the movement history and confirmation message.
- [x] 3. Run focused vacation tests, formatting, build/runtime verification, and inspect the final diff.

## Acceptance criteria
- A successful manual adjustment remains persisted as one append-only movement.
- The adjusted employee and current balance are visible after the modal closes even with zero vacation records.
- The new movement is visible with its reason, actor, type, delta, and timestamp.
- The UI visibly confirms successful adjustment.
- Data from another company is never rendered.
- Existing vacation records, filters, pagination, approval, and cancellation behavior remain unchanged.

## Evidence
- Approved issue: [#382](https://github.com/M3rsy/nomina/issues/382).
- Diagnosis harness: `/tmp/nomina-vacaciones-verification/VacationBalanceUiReproTest.php`.
- Baseline reproduction: no-vacation employee persisted=true, employee shown=false, balance shown=false; existing-vacation control shown=true.
- Ledger RED: missing `data-vacation-section="balances"`; GREEN: 1 test, 6 assertions.
- History/feedback RED: missing success confirmation; GREEN: 1 test, 11 assertions.
- Inactive employee correction: 1 test, 4 assertions; real balances remain visible in ledger and vacation rows.
- Soft-deleted employee correction: 1 test, 3 assertions; archived identity renders without crashing.
- Independent focused verification: 30 tests, 185 assertions plus 5 probe tests, 30 assertions; approved.
- Full regression suite: 1,096 passed, 3 skipped, 5,850 assertions.
- Frontend build: passed; 55 modules transformed.
- Pint and `git diff --check`: passed.
- LSP diagnostics: 4 changed files checked, 0 diagnostics.
- Authored churn: 228 lines including this tracking artifact, below the 400-line review threshold.
- Commit: work-unit commit `fix(vacations): show persisted balance adjustments`.

## Rollback boundary
Remove the balance-ledger/history view sections, their `Index` render queries/state, and the focused regression tests. No database rollback is required.
