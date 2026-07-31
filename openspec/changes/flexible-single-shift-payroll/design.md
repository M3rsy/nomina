# Design: Flexible Single-Shift Payroll

## Approach and Decisions

Deepen `AttendanceShiftAnalyzer`/`PayrollShiftEvaluator`; preserve marks, `Fecha laboral`, exact minutes, append-only decisions, exact-one assignment/profile/schedule failure, immutable history, and legacy/current reports. `duration-first-v2` applies only to the published 06:00–14:00 Monday–Saturday/Sunday-off general profile: first 480 worked minutes ordinary; post-quota wall-clock bands; Sunday/holiday +100%; quota shortfall, variation, ≤30-minute transfer-tail exclusion, and exact partial overtime follow the specs.

| Choice | Rejected | Rationale |
|---|---|---|
| Deep seams | Compatibility layer | One policy/fingerprint. |
| Dated publication | `first()`/midnight flip | Deterministic history. |
| One lock owner | Caller transactions | Canonical order. |
| Versioned rows/adapters | Backfill/recalculation | Legacy truth. |

## Transaction Contract

`PayrollContextTargets(payPeriodIds,profileIds,publicationIds,employeeIds,assignmentIds,rawMarkIds,holidayStart,holidayEnd)` and `LockedPayrollContext(company,payPeriods,profiles,publications,employees,assignments,rawMarks,holidayCalendar)` expose sorted, keyed strict accessors. Only `PayrollContextLocker::within(...)` starts transactions/`FOR UPDATE`: company → periods → profiles → publications → employees → assignments → raw marks (`ORDER BY id`). Resolvers only select IDs; workers never transact/lock.

`HolidayCalendar::captureLocked(LockedPayrollContext $context,CarbonInterface|string $start,CarbonInterface|string|null $end=null)` reads holidays/generations without transaction/lock. `within` locks targets, creates an uncalendared context, calls `captureLocked`, then supplies the final context. Standalone reads call `PayrollContextLocker::captureHolidayCalendar(int $companyId,CarbonInterface|string $start,CarbonInterface|string|null $end=null)`, a single `within`; remove `HolidayCalendar::capture`.

Migrate direct callers exactly: `PayrollContextLocker::within` calls `captureLocked`; `PayrollProcessor::processPayPeriod` and `PayrollPeriodReviewSnapshot::forPeriod` become public locker wrappers plus locked workers; `PayrollShiftEvaluationResolver::{review,resolve}` requires the supplied calendar and loses its fallback; `Livewire/Nomina/Revisar::{moveToReady,periodReviewSnapshot}` uses the wrapper; `tests/Feature/Attendance/{HolidayCalendarTest,PayrollReadinessCheckerTest}.php` and `tests/Support/postgresql-worker.php` call `captureHolidayCalendar`. Remove every surrounding `DB::transaction`. `PayPeriodCreator`, publisher, assigner, and retirer also target all rows and use this owner.

`PayrollContextLockOrderTest` runs every public path, observes transaction level 1, no `SAVEPOINT`/second company lock, exact SQL order, and concurrent calendar mutation/capture, readiness, processing, decisions, creator, publisher, assigner, and retirer without reversal/deadlock.

## Executable Database Truth Tables

All new-policy writers explicitly set `record_version=2`; additive deployment defaults/backfills existing rows to `1`.

### `attendance_exceptions`

Add `record_version smallint NOT NULL DEFAULT 1`; make `starts_at,ends_at` nullable. Existing `deficit_key,fingerprint,segment_kind,minutes,rate_minutes,decision,reason,created_at` remain NOT NULL; `decided_by,supersedes_id` remain nullable.

```sql
CHECK(CASE WHEN record_version=1 THEN TRUE WHEN record_version=2 THEN segment_kind='daily_shortfall' AND starts_at IS NULL AND ends_at IS NULL AND minutes BETWEEN 1 AND 480 AND decision IN('granted','rejected','revoked') AND deficit_key~'^[0-9a-f]{64}$' AND fingerprint~'^[0-9a-f]{64}$' AND decided_by IS NOT NULL AND btrim(reason)<>'' AND ((decision IN('granted','rejected') AND supersedes_id IS NULL)OR(decision='revoked' AND supersedes_id IS NOT NULL)) AND jsonb_typeof(rate_minutes::jsonb)='object' AND (rate_minutes::jsonb-ARRAY['ordinary','extra25','extra50','extra75','extra100']::text[])='{}'::jsonb AND COALESCE((rate_minutes->>'ordinary')::int,0)=minutes AND COALESCE((rate_minutes->>'extra25')::int,0)=0 AND COALESCE((rate_minutes->>'extra50')::int,0)=0 AND COALESCE((rate_minutes->>'extra75')::int,0)=0 AND COALESCE((rate_minutes->>'extra100')::int,0)=0 ELSE FALSE END)
```

No current row means pending; `granted` pays, `rejected` closes unpaid, current `revoked` means pending. A constraint trigger locks the current row matching `company_id,pay_period_id,employee_id,work_date,deficit_key`: grant/reject require none; revoke must supersede that v2 granted row with equal `fingerprint,minutes`. Otherwise SQLSTATE `23514`.

### `attendance_variation_acknowledgements`

Create `id bigserial`; `record_version smallint NOT NULL DEFAULT 2`; non-null bigint FKs `company_id,pay_period_id,employee_id,acknowledged_by` (actor delete restricted); `work_date date`, `variation_key char(64)`, `fingerprint char(64)`, `variation_kind varchar(32)`, `entry_at timestamp(0)`, `reason text`, `created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP`, all NOT NULL; no update/supersession columns.

```sql
CHECK(record_version=2 AND variation_kind='schedule_entry' AND variation_key~'^[0-9a-f]{64}$' AND fingerprint~'^[0-9a-f]{64}$' AND btrim(reason)<>''); UNIQUE(company_id,pay_period_id,employee_id,work_date,variation_key,fingerprint)
```

### `overtime_decisions`

Add `record_version smallint NOT NULL DEFAULT 1` plus nullable `resolution_kind varchar(20),approved_starts_at timestamp(0),approved_ends_at timestamp(0),approved_minutes integer,approved_rate_minutes json,rejected_minutes integer,rejected_rate_minutes json,rejected_before_starts_at timestamp(0),rejected_before_ends_at timestamp(0),rejected_before_minutes integer,rejected_after_starts_at timestamp(0),rejected_after_ends_at timestamp(0),rejected_after_minutes integer,resolution_hash char(64)`. Existing `candidate_key,fingerprint,segment_kind,starts_at,ends_at,minutes,rate_minutes,decision,reason,created_at` remain NOT NULL; `decided_by,supersedes_id,batch_item_id` remain nullable, although v2 requires `decided_by`.

```sql
CHECK(CASE WHEN record_version=1 THEN resolution_kind IS NULL AND approved_starts_at IS NULL AND approved_ends_at IS NULL AND approved_minutes IS NULL AND approved_rate_minutes IS NULL AND rejected_minutes IS NULL AND rejected_rate_minutes IS NULL AND rejected_before_starts_at IS NULL AND rejected_before_ends_at IS NULL AND rejected_before_minutes IS NULL AND rejected_after_starts_at IS NULL AND rejected_after_ends_at IS NULL AND rejected_after_minutes IS NULL AND resolution_hash IS NULL WHEN record_version=2 THEN segment_kind='post_quota_overtime' AND resolution_kind IS NOT NULL AND resolution_kind IN('whole_approve','whole_reject','partial') AND approved_minutes IS NOT NULL AND rejected_minutes IS NOT NULL AND rejected_before_minutes IS NOT NULL AND rejected_after_minutes IS NOT NULL AND approved_minutes>=0 AND rejected_minutes>=0 AND rejected_before_minutes>=0 AND rejected_after_minutes>=0 AND approved_rate_minutes IS NOT NULL AND rejected_rate_minutes IS NOT NULL AND resolution_hash IS NOT NULL AND resolution_hash~'^[0-9a-f]{64}$' AND candidate_key~'^[0-9a-f]{64}$' AND fingerprint~'^[0-9a-f]{64}$' AND decided_by IS NOT NULL AND btrim(reason)<>'' AND ends_at>starts_at AND minutes>0 AND MOD(EXTRACT(EPOCH FROM(ends_at-starts_at))::bigint,60)=0 AND minutes=(EXTRACT(EPOCH FROM(ends_at-starts_at))/60)::int ELSE FALSE END)
```

```sql
CHECK(record_version<>2 OR COALESCE(
 (resolution_kind='whole_approve' AND decision='approved' AND approved_starts_at=starts_at AND approved_ends_at=ends_at AND approved_minutes=minutes AND rejected_minutes=0 AND rejected_before_minutes=0 AND rejected_after_minutes=0 AND rejected_before_starts_at IS NULL AND rejected_before_ends_at IS NULL AND rejected_after_starts_at IS NULL AND rejected_after_ends_at IS NULL)
 OR(resolution_kind='whole_reject' AND decision='rejected' AND approved_starts_at IS NULL AND approved_ends_at IS NULL AND approved_minutes=0 AND rejected_minutes=minutes AND rejected_before_minutes=0 AND rejected_after_minutes=0 AND rejected_before_starts_at IS NULL AND rejected_before_ends_at IS NULL AND rejected_after_starts_at IS NULL AND rejected_after_ends_at IS NULL)
 OR(resolution_kind='partial' AND decision='approved' AND batch_item_id IS NULL AND approved_starts_at>=starts_at AND approved_ends_at<=ends_at AND approved_starts_at<approved_ends_at AND MOD(EXTRACT(EPOCH FROM(approved_starts_at-starts_at))::bigint,60)=0 AND MOD(EXTRACT(EPOCH FROM(approved_ends_at-starts_at))::bigint,60)=0 AND approved_minutes=(EXTRACT(EPOCH FROM(approved_ends_at-approved_starts_at))/60)::int AND approved_minutes BETWEEN 1 AND minutes-1 AND rejected_minutes=minutes-approved_minutes
 AND((approved_starts_at=starts_at AND rejected_before_minutes=0 AND rejected_before_starts_at IS NULL AND rejected_before_ends_at IS NULL)OR(approved_starts_at>starts_at AND rejected_before_starts_at=starts_at AND rejected_before_ends_at=approved_starts_at AND MOD(EXTRACT(EPOCH FROM(rejected_before_ends_at-rejected_before_starts_at))::bigint,60)=0 AND rejected_before_minutes=(EXTRACT(EPOCH FROM(rejected_before_ends_at-rejected_before_starts_at))/60)::int))
 AND((approved_ends_at=ends_at AND rejected_after_minutes=0 AND rejected_after_starts_at IS NULL AND rejected_after_ends_at IS NULL)OR(approved_ends_at<ends_at AND rejected_after_starts_at=approved_ends_at AND rejected_after_ends_at=ends_at AND MOD(EXTRACT(EPOCH FROM(rejected_after_ends_at-rejected_after_starts_at))::bigint,60)=0 AND rejected_after_minutes=(EXTRACT(EPOCH FROM(rejected_after_ends_at-rejected_after_starts_at))/60)::int)) AND rejected_before_minutes+rejected_after_minutes=rejected_minutes),FALSE))
```

```sql
CHECK(record_version<>2 OR(
 jsonb_typeof(rate_minutes::jsonb)='object' AND (rate_minutes::jsonb-ARRAY['ordinary','extra25','extra50','extra75','extra100']::text[])='{}'::jsonb AND LEAST(COALESCE((rate_minutes->>'ordinary')::int,0),COALESCE((rate_minutes->>'extra25')::int,0),COALESCE((rate_minutes->>'extra50')::int,0),COALESCE((rate_minutes->>'extra75')::int,0),COALESCE((rate_minutes->>'extra100')::int,0))>=0
 AND jsonb_typeof(approved_rate_minutes::jsonb)='object' AND (approved_rate_minutes::jsonb-ARRAY['ordinary','extra25','extra50','extra75','extra100']::text[])='{}'::jsonb AND LEAST(COALESCE((approved_rate_minutes->>'ordinary')::int,0),COALESCE((approved_rate_minutes->>'extra25')::int,0),COALESCE((approved_rate_minutes->>'extra50')::int,0),COALESCE((approved_rate_minutes->>'extra75')::int,0),COALESCE((approved_rate_minutes->>'extra100')::int,0))>=0
 AND jsonb_typeof(rejected_rate_minutes::jsonb)='object' AND (rejected_rate_minutes::jsonb-ARRAY['ordinary','extra25','extra50','extra75','extra100']::text[])='{}'::jsonb AND LEAST(COALESCE((rejected_rate_minutes->>'ordinary')::int,0),COALESCE((rejected_rate_minutes->>'extra25')::int,0),COALESCE((rejected_rate_minutes->>'extra50')::int,0),COALESCE((rejected_rate_minutes->>'extra75')::int,0),COALESCE((rejected_rate_minutes->>'extra100')::int,0))>=0
 AND COALESCE((rate_minutes->>'ordinary')::int,0)+COALESCE((rate_minutes->>'extra25')::int,0)+COALESCE((rate_minutes->>'extra50')::int,0)+COALESCE((rate_minutes->>'extra75')::int,0)+COALESCE((rate_minutes->>'extra100')::int,0)=minutes
 AND COALESCE((approved_rate_minutes->>'ordinary')::int,0)+COALESCE((approved_rate_minutes->>'extra25')::int,0)+COALESCE((approved_rate_minutes->>'extra50')::int,0)+COALESCE((approved_rate_minutes->>'extra75')::int,0)+COALESCE((approved_rate_minutes->>'extra100')::int,0)=approved_minutes
 AND COALESCE((rejected_rate_minutes->>'ordinary')::int,0)+COALESCE((rejected_rate_minutes->>'extra25')::int,0)+COALESCE((rejected_rate_minutes->>'extra50')::int,0)+COALESCE((rejected_rate_minutes->>'extra75')::int,0)+COALESCE((rejected_rate_minutes->>'extra100')::int,0)=rejected_minutes
 AND COALESCE((approved_rate_minutes->>'ordinary')::int,0)+COALESCE((rejected_rate_minutes->>'ordinary')::int,0)=COALESCE((rate_minutes->>'ordinary')::int,0)
 AND COALESCE((approved_rate_minutes->>'extra25')::int,0)+COALESCE((rejected_rate_minutes->>'extra25')::int,0)=COALESCE((rate_minutes->>'extra25')::int,0)
 AND COALESCE((approved_rate_minutes->>'extra50')::int,0)+COALESCE((rejected_rate_minutes->>'extra50')::int,0)=COALESCE((rate_minutes->>'extra50')::int,0)
 AND COALESCE((approved_rate_minutes->>'extra75')::int,0)+COALESCE((rejected_rate_minutes->>'extra75')::int,0)=COALESCE((rate_minutes->>'extra75')::int,0)
 AND COALESCE((approved_rate_minutes->>'extra100')::int,0)+COALESCE((rejected_rate_minutes->>'extra100')::int,0)=COALESCE((rate_minutes->>'extra100')::int,0)))
```

A trigger requires NULL supersession only when no current row exists for `company_id,pay_period_id,employee_id,work_date,candidate_key`; otherwise `supersedes_id` must equal that current v2 parent and `fingerprint,segment_kind,starts_at,ends_at,minutes,rate_minutes` must match, or SQLSTATE `23514`.

### Publications

Migration order: add `work_schedule_profiles_company_key_id_unique UNIQUE(company_id,profile_key,id)`; create `id bigserial PRIMARY KEY`, non-null `company_id bigint REFERENCES companies(id),profile_key varchar,profile_id bigint,effective_from date,definition_hash char(64),request_key char(64),payload_hash char(64),published_by bigint REFERENCES users(id) ON DELETE RESTRICT,reason text,created_at timestamp(0),updated_at timestamp(0)`, and nullable `effective_to date`. CHECK `profile_key='general'`, `effective_to IS NULL OR effective_from<effective_to`, `btrim(reason)<>''`, and `definition_hash,request_key,payload_hash` each matching `^[0-9a-f]{64}$`; add unique `(company_id,profile_key,id)` and `(company_id,profile_key,request_key)`; then FK `(company_id,profile_key,profile_id) REFERENCES work_schedule_profiles(company_id,profile_key,id)` and the GiST non-overlap below. Down drops child first. PostgreSQL tests assert cross-company and same-company/wrong-key references fail `23503`. Preserve `23505/23P01` fresh-lock reload: succeed only on identical hashes/profile/date/schedules/assignments, else `publication_conflict`.

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;
ALTER TABLE work_schedule_profile_publications ADD CONSTRAINT profile_publications_no_overlap EXCLUDE USING gist(company_id WITH =,profile_key WITH =,daterange(effective_from,COALESCE(effective_to,'infinity'::date),'[)') WITH &&);
```

## Resolution, Reporting, Files

Fresh provisioning means zero profiles/schedules/assignments/results under lock. Upgrade requires exactly one active non-retired general profile; missing/ambiguous fails. Cutover is first strictly future period; none changes nothing. Backfill publication from earliest assignment/company date, close at cutover, publish/assign prospectively; date-effective publication alone resolves hires. Zero/multiple assignment/profile/schedule blocks review/processing. Snapshot writing is insert-only; `PayrollReportingRowAdapter` maps v1 to `LEGACY` with new fields NULL and never recalculates. Exporters total integer minutes before display conversion.

**Modify:** `app/Services/Attendance/{AttendanceShiftAnalyzer,AttendanceShiftAnalysis,ShiftOccurrenceResolver,PayrollPeriodSnapshotData,PayrollShiftEvaluator,PayrollShiftEvaluationResolver,PayrollPeriodReviewSnapshot,PayrollReadinessChecker,AttendanceReviewQuery,AttendanceExceptionRecorder,OvertimeDecisionRecorder,OvertimeDecisionBatchRequester,EmployeeScheduleAssigner,DefaultWorkScheduleProvisioner,WorkScheduleProfileRetirer,HolidayCalendar}.php`; `app/Services/Payroll/{PayrollContextTargets,LockedPayrollContext,PayrollContextLocker,PayPeriodRangeGuard,PayrollProcessor,PayrollExcelExporter,PayrollStubExporter}.php`; `app/Models/{AttendanceException,OvertimeDecision,PayrollResult,WorkScheduleProfile}.php`; `app/Livewire/{Nomina/Index,Nomina/Revisar,Jornadas/Index,Empleados/Create,Empleados/Edit}.php`; `resources/views/livewire/{nomina/revisar,jornadas/index}.blade.php`; `database/factories/{AttendanceExceptionFactory,OvertimeDecisionFactory,PayrollResultFactory,WorkScheduleProfileFactory,WorkScheduleFactory,EmployeeScheduleAssignmentFactory,PayPeriodFactory}.php`; `tests/Feature/Attendance/{AttendanceShiftAnalyzerTest,ShiftOccurrenceResolverTest,PayrollShiftEvaluatorTest,PayrollReadinessCheckerTest,AttendanceReviewQueryTest,AttendanceExceptionRecorderTest,OvertimeDecisionRecorderTest,OvertimeDecisionBatchRequesterTest,HolidayCalendarTest}.php`; `tests/Feature/Empleados/EmployeeScheduleAssignmentTest.php`; `tests/Feature/Jornadas/WorkScheduleProfileRetirerTest.php`; `tests/Feature/Nomina/{IndexTest,RevisarTest,AttendanceReviewTest,ExcelStructureTest,ExportarExcelTest}.php`; `tests/Feature/Payroll/PayrollProcessorTest.php`; `tests/Support/postgresql-worker.php`; `CONTEXT.md`; `docs/adr/0001-resolve-payroll-by-assigned-shift.md`.

**Create:** `app/Models/{WorkScheduleProfilePublication,AttendanceVariationAcknowledgement}.php`; `app/Services/Attendance/{GeneralWorkSchedulePublisher,GeneralWorkScheduleResolver,VariationAcknowledgementRecorder}.php`; `app/Services/Payroll/{PayPeriodCreator,PayrollDaySnapshot,PayrollDaySnapshotWriter,PayrollReportingRowAdapter}.php`; `database/migrations/2026_07_30_{000001_add_daily_shortfall_to_attendance_exceptions,000002_add_partial_overtime_resolution,000003_create_attendance_variation_acknowledgements,000004_create_work_schedule_profile_publications,000005_add_payroll_day_snapshot_to_payroll_results}.php`; `tests/PostgreSQL/{GeneralProfilePublicationConcurrencyTest,PayrollContextLockOrderTest}.php`; `docs/adr/0002-duration-first-payroll.md`.

## Strict-TDD Delivery and Rollback

Nine vertical RED→GREEN→REFACTOR/GREEN slices remain: (1) quota/bands/override; (2) variation/tail API+UI; (3) shortfall lifecycle/UI; (4) exact-one blockers; (5) whole/reject/exact-partial overtime; (6) locker/publication/resolution/autoassignment; (7) immutable snapshot; (8) reports/totals; (9) glossary/ADR/acceptance. Each keeps its tests/migration and prior rollback seam.

Forced issue-linked Feature Branch Chain remains: draft/no-merge tracker; child 1 targets tracker, later child targets predecessor; exactly `type:feature`; prefer 400 authored lines, split before 800; conventional commits/no attribution. Post-apply 4R review produces a complete-content/current-HEAD receipt, revalidated pre-commit/push/PR and renewed after any change.

## Threat Matrix

| Boundary | Applicability | Safe/failure/RED |
|---|---|---|
| HTTP/Livewire | Applicable | Authorized tenant/current fingerprint succeeds; foreign/unauthorized/stale/locked writes nothing; Livewire REDs. |
| Documentation-like paths | N/A — no executable classification | None. |
| Git repository selection | N/A — no Git execution | None. |
| Commit state | N/A — no commit automation | None. |
| Push state | N/A — no push automation | None. |
| PR commands | N/A — no PR automation | None. |

Deploy schema/read compatibility → code → explicit publication. Rollback disables v2/publication but retains audit/history. No open questions.
