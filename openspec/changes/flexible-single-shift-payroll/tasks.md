# Tasks: Flexible Single-Shift Payroll

## Review Workload Forecast

Estimate: 4,200–5,800 authored; ~400/slice, split before 800; auto-chain.

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | PR/base | Focused command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Quota/bands | PR1/tracker | `./vendor/bin/pest tests/Feature/Attendance/AttendanceShiftAnalyzerTest.php` | G; `./vendor/bin/pest tests/Feature/Nomina/AttendanceReviewTest.php` | `app/Services/Attendance/{AttendanceShiftAnalyzer,AttendanceShiftAnalysis,ShiftOccurrenceResolver,PayrollShiftEvaluator}.php` |
| 2 | Variation/tail | PR2/PR1 | `./vendor/bin/pest tests/Feature/Attendance/AttendanceReviewQueryTest.php` | G; `./vendor/bin/pest tests/Feature/Nomina/RevisarTest.php` | `database/migrations/2026_07_30_000003_create_attendance_variation_acknowledgements.php`,`app/{Models/AttendanceVariationAcknowledgement,Services/Attendance/VariationAcknowledgementRecorder,Services/Attendance/AttendanceShiftAnalyzer,Livewire/Nomina/Revisar}.php` |
| 3 | Shortfall | PR3/PR2 | `./vendor/bin/pest tests/Feature/Attendance/AttendanceExceptionRecorderTest.php` | G; `./vendor/bin/pest tests/Feature/Nomina/RevisarTest.php` | `database/migrations/2026_07_30_000001_add_daily_shortfall_to_attendance_exceptions.php`,`app/{Models/AttendanceException,Services/Attendance/AttendanceExceptionRecorder,Services/Attendance/PayrollShiftEvaluationResolver,Services/Attendance/PayrollReadinessChecker,Livewire/Nomina/Revisar}.php` |
| 4 | Exact-one | PR4/PR3 | `./vendor/bin/pest tests/Feature/Attendance/PayrollReadinessCheckerTest.php` | G; `./vendor/bin/pest tests/Feature/Nomina/AttendanceReviewTest.php` | `app/Services/Attendance/{ShiftOccurrenceResolver,PayrollPeriodReviewSnapshot,PayrollShiftEvaluationResolver,PayrollReadinessChecker}.php` |
| 5 | Overtime | PR5/PR4 | `./vendor/bin/pest tests/Feature/Attendance/OvertimeDecisionRecorderTest.php` | G; `./vendor/bin/pest tests/Feature/Nomina/RevisarTest.php` | `database/migrations/2026_07_30_000002_add_partial_overtime_resolution.php`,`app/{Models/OvertimeDecision,Services/Attendance/OvertimeDecisionRecorder,Services/Attendance/OvertimeDecisionBatchRequester,Livewire/Nomina/Revisar}.php` |
| 6 | Publication | PR6/PR5 | `./vendor/bin/phpunit -c phpunit.postgresql.xml tests/PostgreSQL/GeneralProfilePublicationConcurrencyTest.php` | G; `./vendor/bin/phpunit -c phpunit.postgresql.xml tests/PostgreSQL/PayrollContextLockOrderTest.php` | `database/migrations/2026_07_30_000004_create_work_schedule_profile_publications.php`,`app/Models/{WorkScheduleProfilePublication,WorkScheduleProfile}.php`,`app/Services/Attendance/{GeneralWorkSchedulePublisher,GeneralWorkScheduleResolver,EmployeeScheduleAssigner,DefaultWorkScheduleProvisioner,WorkScheduleProfileRetirer,HolidayCalendar,PayrollPeriodReviewSnapshot}.php`,`app/Services/Payroll/{PayrollContextTargets,LockedPayrollContext,PayrollContextLocker,PayPeriodCreator,PayPeriodRangeGuard,PayrollProcessor}.php` |
| 7 | Snapshots | PR7/PR6 | `./vendor/bin/pest tests/Feature/Payroll/PayrollProcessorTest.php` | G; focused command | `database/migrations/2026_07_30_000005_add_payroll_day_snapshot_to_payroll_results.php`,`app/Services/Payroll/{PayrollDaySnapshot,PayrollDaySnapshotWriter,PayrollProcessor}.php`,`app/Models/PayrollResult.php` |
| 8 | Reports | PR8/PR7 | `./vendor/bin/pest tests/Feature/Nomina/ExcelStructureTest.php` | G; `./vendor/bin/pest tests/Feature/Nomina/ExportarExcelTest.php` | `app/Services/Payroll/{PayrollReportingRowAdapter,PayrollExcelExporter,PayrollStubExporter}.php`,`app/Livewire/Nomina/Index.php` |
| 9 | Docs/acceptance | PR9/PR8 | `./vendor/bin/pest` | N/A—no new runtime boundary | `CONTEXT.md`,`docs/adr/{0001-resolve-payroll-by-assigned-shift,0002-duration-first-payroll}.md` |

**G:** before runtime, clear native SDD attempt-ledger; record attempt/result and RED/GREEN/post-refactor evidence; stop duplicate/blocked. Every semicolon case gets RED→minimal GREEN→REFACTOR/green.

## Phase 0: Delivery Prerequisites

- [ ] 0.1 Confirm duplicate search, approved feature issue, draft/no-merge tracker, predecessor bases, issue links, exactly `type:feature`, ~400/<800 authored lines.

## Phase 1: Quota, Bands, Overrides

- [ ] 1.1 In `tests/Feature/Attendance/{AttendanceShiftAnalyzerTest,ShiftOccurrenceResolverTest,PayrollShiftEvaluatorTest}.php` RED→GREEN `app/Services/Attendance/{AttendanceShiftAnalyzer,AttendanceShiftAnalysis,ShiftOccurrenceResolver,PayrollShiftEvaluator}.php`: 17:30–18:30→30@25+30@50; 480m59s→480/preserved marks; 06–14,08–16,09–17,12–20→480 ordinary; 06–16→480+120@25; 09–19→480+60@25+60@50; 12–21→480+60@50; 00–09→480+60@25; Sunday/holiday→all@100; Saturday overnight→Saturday/+75.

## Phase 2: Variation and Transfer Tail

- [ ] 2.1 RED→GREEN `tests/Feature/Attendance/{AttendanceShiftAnalyzerTest,AttendanceReviewQueryTest}.php`,`tests/Feature/Nomina/RevisarTest.php` → `database/migrations/2026_07_30_000003_create_attendance_variation_acknowledgements.php`,`app/Models/AttendanceVariationAcknowledgement.php`,`app/Services/Attendance/{AttendanceShiftAnalyzer,VariationAcknowledgementRecorder,AttendanceReviewQuery}.php`,`app/Livewire/Nomina/Revisar.php`,`resources/views/livewire/nomina/revisar.blade.php`: 06:20–14:20 no variation; 07–15 nonblocking variation; 16:25→120@25+25 excluded; 16:31→151@25; authorized/current acknowledgement preserves pay; foreign/unauthorized/stale/locked writes nothing.

## Phase 3: Daily Shortfall

- [ ] 3.1 RED→GREEN `tests/Feature/Attendance/{AttendanceExceptionRecorderTest,PayrollReadinessCheckerTest}.php`,`tests/Feature/Nomina/RevisarTest.php` → `database/migrations/2026_07_30_000001_add_daily_shortfall_to_attendance_exceptions.php`,`app/Models/AttendanceException.php`,`database/factories/AttendanceExceptionFactory.php`,`app/Services/Attendance/{AttendanceExceptionRecorder,PayrollShiftEvaluationResolver,PayrollReadinessChecker}.php`,`app/Livewire/Nomina/Revisar.php`,`resources/views/livewire/nomina/revisar.blade.php`: 07–14→420+one noninterval 60/no variation; pending blocks/writes nothing; GRANTED→480; REJECTED→420; revoke GRANTED→pending; invalid/stale/foreign/unauthorized/locked/v2-SQL→no append/`23514`.

## Phase 4: Exact-One Resolution

- [ ] 4.1 RED→GREEN `tests/Feature/Attendance/{ShiftOccurrenceResolverTest,PayrollReadinessCheckerTest,HolidayCalendarTest}.php`,`tests/Feature/Nomina/AttendanceReviewTest.php` → `app/Services/Attendance/{ShiftOccurrenceResolver,PayrollPeriodReviewSnapshot,PayrollShiftEvaluationResolver,PayrollReadinessChecker}.php`: zero/multiple assignment/profile/schedule blocks review/processing and writes nothing.

## Phase 5: Overtime Resolution

- [ ] 5.1 RED→GREEN `tests/Feature/Attendance/{OvertimeDecisionRecorderTest,OvertimeDecisionBatchRequesterTest}.php`,`tests/Feature/Nomina/{RevisarTest,AttendanceReviewTest}.php` → `database/migrations/2026_07_30_000002_add_partial_overtime_resolution.php`,`app/Models/OvertimeDecision.php`,`database/factories/OvertimeDecisionFactory.php`,`app/Services/Attendance/{OvertimeDecisionRecorder,OvertimeDecisionBatchRequester}.php`,`app/Livewire/Nomina/Revisar.php`,`resources/views/livewire/nomina/revisar.blade.php`: whole approve/reject preserves candidate; 17–18→60@25; 18–19→60@50; 17:30–18:30→30@25+30@50/rejected complements; batch partial refused; stale/foreign/unauthorized/locked writes nothing; invalid-v2 SQL→`23514`.

## Phase 6: Effective-Dated Publication

- [ ] 6.1 RED→GREEN `tests/Feature/{Empleados/EmployeeScheduleAssignmentTest,Jornadas/WorkScheduleProfileRetirerTest}.php`,`tests/PostgreSQL/{GeneralProfilePublicationConcurrencyTest,PayrollContextLockOrderTest}.php` → `database/migrations/2026_07_30_000004_create_work_schedule_profile_publications.php`,`app/Models/{WorkScheduleProfilePublication,WorkScheduleProfile}.php`,`app/Services/Attendance/{GeneralWorkSchedulePublisher,GeneralWorkScheduleResolver,EmployeeScheduleAssigner,DefaultWorkScheduleProvisioner,WorkScheduleProfileRetirer}.php`,`app/Livewire/{Jornadas/Index,Empleados/Create,Empleados/Edit}.php`,`resources/views/livewire/jornadas/index.blade.php`,`database/factories/{WorkScheduleProfileFactory,WorkScheduleFactory,EmployeeScheduleAssignmentFactory,PayPeriodFactory}.php`: next future period; none→unchanged; prospective history; locked conflict atomic; hires before/on/after; zero/multiple fail; idempotent retry; concurrent same request; overlap/FK→`23P01`/`23503`.
- [ ] 6.2 GREEN lock order through `app/Services/Payroll/{PayrollContextTargets,LockedPayrollContext,PayrollContextLocker,PayPeriodCreator,PayPeriodRangeGuard,PayrollProcessor}.php`,`app/Services/Attendance/{HolidayCalendar,PayrollPeriodReviewSnapshot}.php`,`tests/Support/postgresql-worker.php`: one transaction/company→periods→profiles→publications→employees→assignments→marks; no savepoint/deadlock.

## Phase 7: Immutable Snapshots

- [ ] 7.1 RED→GREEN `tests/Feature/Payroll/PayrollProcessorTest.php` → `database/migrations/2026_07_30_000005_add_payroll_day_snapshot_to_payroll_results.php`,`app/Services/Payroll/{PayrollDaySnapshot,PayrollDaySnapshotWriter,PayrollProcessor}.php`,`app/Models/PayrollResult.php`,`database/factories/PayrollResultFactory.php`,`app/Services/Attendance/PayrollPeriodSnapshotData.php`: ready captures integer-minute/audit facts and `rules_version`; blocked writes nothing; identical retry returns existing; reset/conflict never rewrites.

## Phase 8: Reports

- [ ] 8.1 RED→GREEN `tests/Feature/Nomina/{ExcelStructureTest,ExportarExcelTest,IndexTest}.php` → `app/Services/Payroll/{PayrollReportingRowAdapter,PayrollExcelExporter,PayrollStubExporter}.php`,`app/Livewire/Nomina/Index.php`: legacy fields null/`LEGACY`; required columns/subtotals/grand totals; two one-minute rows total two before conversion.

## Phase 9: Documentation and Lifecycle

- [ ] 9.1 Update `CONTEXT.md`, supersede `docs/adr/0001-resolve-payroll-by-assigned-shift.md`, and add `docs/adr/0002-duration-first-payroll.md`; run canonical Pest/SQLite and PostgreSQL suites.
- [ ] 9.2 Post-apply, parent starts ordinary-4R `review/start(target)` only absent a valid receipt; persist transaction/ledger/content/HEAD-bound receipt. Validate it pre-commit/push/PR; changes invalidate/renew it; tracker stays no-merge.
