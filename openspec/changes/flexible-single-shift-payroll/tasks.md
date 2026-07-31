# Tasks: Flexible Single-Shift Payroll

## Review Workload Forecast

Estimate: 4,700–6,400 authored lines; ~400/child; split before 800; auto-chain.

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

### Suggested Work Units

| Slice/base/goal | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|
| PR1←tracker-branch:identity | `./vendor/bin/pest tests/Feature/Attendance/ShiftOccurrenceResolverTest.php` | `./vendor/bin/phpunit -c phpunit.postgresql.xml tests/PostgreSQL/PayrollPolicyIdentityTest.php` | `database/migrations/2026_07_30_000001_create_work_schedule_profile_publications.php,app/Models/WorkScheduleProfilePublication.php,app/Services/Attendance/{ShiftOccurrence,ShiftOccurrenceResolver,AttendanceShiftAnalyzer,PayrollPeriodSnapshotData}.php`;retain-rows |
| PR2←PR1-identity-branch:quota | `./vendor/bin/pest tests/Feature/Attendance/AttendanceShiftAnalyzerTest.php` | `./vendor/bin/pest tests/Feature/Nomina/AttendanceReviewTest.php` | `app/Services/Attendance/{AttendanceShiftAnalyzer,AttendanceShiftAnalysis,ShiftOccurrenceResolver,PayrollShiftEvaluator}.php` |
| PR3←PR2-quota-branch:variation | `./vendor/bin/pest tests/Feature/Attendance/AttendanceReviewQueryTest.php` | `./vendor/bin/pest tests/Feature/Nomina/RevisarTest.php` | `database/migrations/2026_07_30_000004_create_attendance_variation_acknowledgements.php,app/{Models/AttendanceVariationAcknowledgement,Services/Attendance/{AttendanceShiftAnalyzer,VariationAcknowledgementRecorder},Livewire/Nomina/Revisar}.php` |
| PR4←PR3-variation-branch:shortfall | `./vendor/bin/pest tests/Feature/Attendance/AttendanceExceptionRecorderTest.php` | `./vendor/bin/pest tests/Feature/Nomina/RevisarTest.php` | `database/migrations/2026_07_30_000002_add_daily_shortfall_to_attendance_exceptions.php,app/{Models/AttendanceException,Services/Attendance/AttendanceExceptionRecorder,Livewire/Nomina/Revisar}.php` |
| PR5←PR4-shortfall-branch:blockers | `./vendor/bin/pest tests/Feature/Empleados/EmployeeScheduleAssignmentTest.php` | `./vendor/bin/phpunit -c phpunit.postgresql.xml tests/PostgreSQL/PayrollContextLockOrderTest.php` | `app/Services/Payroll/{PayrollContextTargets,LockedPayrollContext,PayrollContextLocker}.php,app/Services/Attendance/EmployeeScheduleAssigner.php,app/Jobs/ProcessPayrollRun.php` |
| PR6←PR5-blockers-branch:overtime | `./vendor/bin/pest tests/Feature/Attendance/OvertimeDecisionRecorderTest.php` | `./vendor/bin/pest tests/Feature/Nomina/RevisarTest.php` | `database/migrations/2026_07_30_000003_add_partial_overtime_resolution.php,app/{Models/OvertimeDecision,Services/Attendance/OvertimeDecisionRecorder,Livewire/Nomina/Revisar}.php` |
| PR7←PR6-overtime-branch:activation/UI/reassignment | `./vendor/bin/pest tests/Feature/Empleados/EmployeeScheduleAssignmentTest.php` | `./vendor/bin/phpunit -c phpunit.postgresql.xml tests/PostgreSQL/GeneralProfilePublicationConcurrencyTest.php` | `app/Services/Attendance/{GeneralWorkSchedulePublisher,GeneralWorkScheduleResolver}.php,app/Livewire/{Jornadas/Index,Empleados/Create,Empleados/Edit}.php`;retain-publications |
| PR8←PR7-activation-branch:snapshots | `./vendor/bin/pest tests/Feature/Payroll/PayrollProcessorTest.php` | `./vendor/bin/pest tests/Feature/Payroll/ProcessPayrollRunTest.php` | `database/migrations/2026_07_30_000005_add_payroll_day_snapshot_to_payroll_results.php,app/Services/Payroll/{PayrollDaySnapshot,PayrollDaySnapshotWriter,PayrollProcessor}.php` |
| PR9←PR8-snapshots-branch:reports | `./vendor/bin/pest tests/Feature/Nomina/ExcelStructureTest.php` | `./vendor/bin/pest tests/Feature/Nomina/ExportarExcelTest.php` | `app/Services/Payroll/{PayrollReportingRowAdapter,PayrollExcelExporter,PayrollStubExporter}.php,app/Livewire/Nomina/Index.php` |
| PR10←PR9-reports-branch:docs | `./vendor/bin/pest` | N/A—documentation-has-no-runtime-boundary | `CONTEXT.md`+ADRs+lifecycle-evidence |

`A←B`: A targets B. Each behavior records literal RED→minimal GREEN→post-refactor GREEN plus native attempt, focused/harness result, rollback.

## Phase 0: Delivery

- [ ] 0.1 Preserve #165, duplicate check, draft/no-merge tracker #166, predecessor targets/diagrams/diffs, links, exactly `type:feature`, conventional commits/no attribution, and ~400/<800 limits.
- [ ] 0.2 RED before each GREEN in `tests/Feature/Nomina/{RevisarTest,IndexTest}.php`,`tests/Feature/{Empleados/EmployeeScheduleAssignmentTest,Jornadas/WorkScheduleProfileRetirerTest}.php`: authorized same-tenant/current-fingerprint succeeds; foreign/unauthorized/stale/locked writes nothing. Shell/process/VCS/PR threats are N/A.

## Phase 1: Immutable Policy Identity

- [x] 1.1 RED one case/cycle in `tests/Feature/Attendance/ShiftOccurrenceResolverTest.php`,`tests/PostgreSQL/PayrollPolicyIdentityTest.php`: legacy parity/total coverage, inclusive final-date preservation, shared-profile coalescing/non-overlap, exact-one `Fecha laboral`, and `23514/23503/23505/23P01` failures.
- [x] 1.2 GREEN `database/migrations/2026_07_30_000001_create_work_schedule_profile_publications.php`,`app/Models/WorkScheduleProfilePublication.php`,`app/Services/Attendance/{ShiftOccurrence,ShiftOccurrenceResolver,AttendanceShiftAnalyzer,AttendanceShiftAnalysis,PayrollShiftEvaluator,PayrollShiftEvaluationResolver,AttendanceReviewQuery,PayrollPeriodSnapshotData}.php`,`app/Services/Payroll/PayrollProcessor.php`: publication-level constrained key; legacy=`schedule-overlap-v1`; only explicit new general publication permits `duration-first-v2` (PR1 creates none); readonly `ShiftOccurrence(publicationId,payrollPolicyKey)` feeds dispatch,fingerprints,snapshot-provenance.

## Phase 2: Recognition and Decisions

- [ ] 2.1 Cycle `tests/Feature/Attendance/{AttendanceShiftAnalyzerTest,ShiftOccurrenceResolverTest,PayrollShiftEvaluatorTest}.php`→`app/Services/Attendance/{AttendanceShiftAnalyzer,AttendanceShiftAnalysis,ShiftOccurrenceResolver,PayrollShiftEvaluator}.php`: 17:30–18:30,480m59s,06–14/08–16/09–17/12–20,06–16,09–19,12–21,00–09,Sunday/holiday,Saturday-overnight exact minutes; V1 stays schedule-overlap.
- [ ] 2.2 RED `RevisarTest::variation_transfer_tail`: 07–15 audit;16:25-tail excluded.
- [ ] 2.3 GREEN `database/migrations/2026_07_30_000004_create_attendance_variation_acknowledgements.php`,`app/{Models/AttendanceVariationAcknowledgement,Services/Attendance/VariationAcknowledgementRecorder,Livewire/Nomina/Revisar}.php`: 06:20–14:20 none; 07–15 pay-neutral audit; 16:25→120+25 excluded; 16:31→151; append-only V2.
- [ ] 2.4 RED `RevisarTest::daily_shortfall`: 07–14 pending/grant/reject/revoke.
- [ ] 2.5 GREEN `database/migrations/2026_07_30_000002_add_daily_shortfall_to_attendance_exceptions.php`,`app/{Models/AttendanceException,Services/Attendance/{AttendanceExceptionRecorder,PayrollReadinessChecker},Livewire/Nomina/Revisar}.php`: 07–14→420+noninterval60/no variation; pending blocks; grant480; reject420; revoke only grant→pending; V1 parity/V2 `23514`.

## Phase 3: Processing and Activation

- [ ] 3.1 Cycle locker/processor/job plus `EmployeeScheduleAssignmentTest::lock_order`: PR5 makes assigner company→profile; forbids nested/inverted path; zero/multiple blocks; job-state transactions stay outside payroll.
- [ ] 3.2 RED `RevisarTest::partial_overtime`: 17:30–18:30 partial preserves complements/bands.
- [ ] 3.3 GREEN `database/migrations/2026_07_30_000003_add_partial_overtime_resolution.php`,`app/{Models/OvertimeDecision,Services/Attendance/{OvertimeDecisionRecorder,OvertimeDecisionBatchRequester},Livewire/Nomina/Revisar}.php`: immutable whole decisions; exact 17–18,18–19,17:30–18:30 partial/complements; batch-partial refusal; band conservation/supersession; V1 parity/V2 `23514`.
- [ ] 3.4 RED `Jornadas/IndexTest::activation` + `EmployeeScheduleAssignmentTest::reassignment`: locked-unchanged;prospective exact-one.
- [ ] 3.5 GREEN publisher/resolver/retirer/UI use PR5-migrated assigner: next-period activation; no-eligible/locked unchanged; hire-boundaries/history/exact-one/idempotency; `23503/23505/23P01` conflicts fail atomically.

## Phase 4: Snapshots and Reports

- [ ] 4.1 RED `PayrollProcessorTest::immutable_snapshot`: retry reuses;reset/conflict never-rewrite.
- [ ] 4.2 GREEN snapshot migration/writer/processor persist publication/key,`rules_version`,marks,decisions,rates,rejected-complement,transfer/variation audit; blocked writes nothing; identical retry reuses.
- [ ] 4.3 Cycle `tests/Feature/Nomina/{ExcelStructureTest,ExportarExcelTest,IndexTest}.php`→`app/Services/Payroll/{PayrollReportingRowAdapter,PayrollExcelExporter,PayrollStubExporter}.php`: legacy null/blank+`LEGACY`,no-recalculation; all columns,employee/grand totals; integer-minute sums,including two one-minute rows.

## Phase 5: Documentation and Lifecycle

- [ ] 5.1 Update `CONTEXT.md`,supersede `docs/adr/0001-resolve-payroll-by-assigned-shift.md`,add `docs/adr/0002-duration-first-payroll.md`; run canonical Pest/SQLite/PostgreSQL suites.
- [ ] 5.2 Parent runs full 4R review; persist native transaction,findings/fixes/re-judgments,complete-content+HEAD receipt; validate pre-commit/push/PR. Planning/implementation/content/HEAD changes invalidate/full-renew; tracker stays no-merge.
