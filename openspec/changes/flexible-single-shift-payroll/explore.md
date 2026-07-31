## Exploration: Flexible single-shift payroll

### Current State

#### Confirmed flow and seams

- Review/readiness follows `PayrollPeriodReviewSnapshot::forPeriod(...)` -> `PayrollShiftEvaluationResolver::review(...)` -> `ShiftOccurrenceResolver::resolveFromSnapshot(...)` -> `AttendanceShiftAnalyzer::analyze(...)` -> `PayrollShiftEvaluator::evaluate(...)`. `PayrollReadinessChecker` exposes evaluator blockers.
- Processing follows `PayrollProcessor::processPayPeriod(...)` -> `PayrollShiftEvaluationResolver::resolve(...)` -> the same analyzer/evaluator seam -> `PayrollProcessor::storeResult(...)`. Processing locks the company and period, requires `ready`, and rolls the transaction back on a blocker.
- `AttendanceExceptionRecorder` and `OvertimeDecisionRecorder` independently recompute the current analysis under `PayrollContextLocker`, validate candidate fingerprints, and append superseding decisions. This is the correct audited decision seam to preserve.
- `PayrollExcelExporter` and `PayrollStubExporter` read stored `PayrollResult` rows rather than recalculating marks. The stub sums integer minutes before converting totals to hours; the global export has no employee/grand totals.
- Schedule resolution is effective-dated at `EmployeeScheduleAssignment`, not at `WorkScheduleProfile`. `EmployeeScheduleAssigner` already locks company -> periods -> employees -> assignments and rejects changes overlapping locked periods.

#### Current behavior inconsistent with the approved policy

1. `AttendanceShiftAnalyzer` classifies by overlap with 06:00-14:00. Thus 08:00-16:00 becomes 360 ordinary minutes, a 120-minute late deficit, and a 120-minute overtime candidate. The approved duration-first rule requires 480 ordinary minutes and only an informational entry variation.
2. Deficits are emitted as separate `late_arrival`, `early_departure`, or `full_day_absence` segments. Unresolved deficits do not block payroll; they are simply unpaid. `AttendanceException` supports only GRANTED and REVOKED, so explicit REJECTED and the required unresolved state do not exist.
3. The canonical wall-clock bands and Sunday/holiday +100% override already exist, but they are applied before the ordinary quota is satisfied. Excess before 14:00 can currently remain ordinary.
4. No entry-tolerance/variation alert, audited acknowledgement, transfer-tail exclusion, or excluded-transfer snapshot exists.
5. Overtime decisions require an exact whole-candidate match. `OvertimeDecisionRecorder` rejects any other shape, `PayrollShiftEvaluator` only accepts whole APPROVED/REJECTED records, and batch requests represent whole candidates.
6. The default general profile is 06:00-14:00 Monday-Friday, 08:00-12:00 Saturday, Sunday off. `Jornadas\Index::storeProfileVersion(...)` immediately deactivates the prior version but does not prospectively reassign employees. New employee creation selects the first active profile rather than enforcing one date-effective general profile.
7. `PayrollResult` snapshots marks, identity, exact minute buckets, calendar generation, and `rules_version`, but not shortfall state/reason, overtime selection/rejected remainder, informational variation/acknowledgement, or excluded transfer minutes. Global/stub reports omit those fields; only the stub has totals.
8. Historical exports are snapshot-based, but `PayrollProcessor` deliberately updates existing result rows if a processed period is manually reset to `ready`. That current idempotence test conflicts with the stronger “never recalculate historical/locked payroll” safeguard.
9. `CONTEXT.md` and ADR `docs/adr/0001-resolve-payroll-by-assigned-shift.md` state that overtime is outside the assigned schedule and may only be approved or rejected as a complete candidate. Both statements are superseded by duration-first recognition and exact contiguous partial authorization.

#### Invariants to retain

- Preserve `Marca observada` timestamps and revisions; derived payroll facts must never edit or fabricate marks.
- Keep `Fecha laboral`, overnight mark partitioning, integer-minute quantization, tenant isolation, holiday-calendar generation, and stale-decision fingerprints.
- Keep decisions append-only with actor, reason, timestamp, and supersession; REVOKED may only supersede a prior GRANTED shortfall decision.
- Sunday/holiday classification overrides the ordinary quota: all observed minutes are +100%, ordinary is zero.
- Store and sum integer minutes; convert to decimal hours only at report presentation.

### Affected Areas

- `app/Services/Attendance/AttendanceShiftAnalyzer.php` — deep calculation seam; replace schedule-overlap internals with duration-first quota, transfer-tail, variation, and override rules while retaining `analyze(...)` as the public interface.
- `app/Services/Attendance/AttendanceShiftAnalysis.php`, `AttendanceSegment.php` — result vocabulary must represent one `daily_shortfall`, one full post-quota candidate, excluded transfer minutes, and informational variation without inventing observed facts.
- `app/Services/Attendance/PayrollShiftEvaluator.php` — add pending-shortfall blockers, GRANTED/REJECTED/REVOKED semantics, and whole/partial overtime evaluation.
- `app/Services/Attendance/AttendanceExceptionRecorder.php`, `app/Models/AttendanceException.php` — add explicit REJECTED and enforce valid append-only transitions.
- `app/Services/Attendance/OvertimeDecisionRecorder.php`, `app/Models/OvertimeDecision.php` — preserve the full candidate snapshot while recording either full approval, full rejection, or one exact contiguous approved interval and its derived rate minutes.
- `app/Services/Attendance/OvertimeDecisionBatchRequester.php` — keep batch behavior whole-candidate only; partial authorization must remain an individual decision path.
- `app/Services/Attendance/PayrollPeriodReviewSnapshot.php`, `PayrollReadinessChecker.php`, `AttendanceReviewQuery.php` — expose one shortfall and nonblocking variation consistently to review/readiness surfaces.
- `app/Services/Attendance/EmployeeScheduleAssigner.php`, `DefaultWorkScheduleProvisioner.php`, `app/Livewire/Jornadas/Index.php` — publish the new general version at the next not-started period and assign it prospectively without mutating prior schedule rows.
- `app/Models/WorkScheduleProfile.php`, `WorkSchedule.php`, `EmployeeScheduleAssignment.php` and migrations — add date-effective profile availability/activation invariants while retaining assignment history.
- `app/Services/Payroll/PayrollProcessor.php`, `app/Models/PayrollResult.php` — create an immutable daily reporting snapshot and bump `rules_version`; remove historical overwrite behavior.
- `app/Services/Payroll/PayrollExcelExporter.php`, `PayrollStubExporter.php`, `tests/Feature/Nomina/ExcelStructureTest.php` — report all approved fields plus employee and grand totals from integer minutes.
- `CONTEXT.md`, `docs/adr/0001-resolve-payroll-by-assigned-shift.md`, planned `docs/adr/0002-*.md` — add/refine canonical terms and explicitly supersede the prior whole-candidate/schedule-overlap decision.

### Approaches

1. **Deepen the existing analyzer/evaluator seams** — retain the public flow and replace overlap-based implementation with a duration-first analysis result consumed by audited recorders, processor, and reports.
   - Pros: Concentrates policy in one high-leverage interface; existing readiness, recorder, processor, and focused tests continue to cross the same seam; minimizes caller churn; preserves fingerprint/audit infrastructure.
   - Cons: `AttendanceShiftAnalysis`, persisted decision snapshots, and report snapshots need coordinated additive migrations; existing overlap-oriented tests must be replaced behavior by behavior.
   - Effort: High

2. **Add a compatibility calculation layer after the current analyzer** — reinterpret overlap deficits/candidates inside the evaluator or processor.
   - Pros: Smaller apparent change to `AttendanceShiftAnalyzer`.
   - Cons: Duplicates policy across analysis, review, decisions, processing, and exports; stale candidate identities would describe the wrong facts; creates a shallow pass-through and makes partial authorization/tail exclusion unsafe.
   - Effort: High and not recommended

### Recommendation

Use approach 1 and keep `AttendanceShiftAnalyzer::analyze(...)` -> `AttendanceShiftAnalysis` as the deep calculation seam. Its implementation should apply this ordered policy:

1. Quantize the observed interval once to exact whole minutes and preserve original entry/exit timestamps.
2. On Sunday/holiday, classify all observed minutes +100% and bypass ordinary quota/variation/shortfall.
3. On working Monday-Saturday dates, allocate the first `min(actual, 480)` minutes to ordinary regardless of entry time.
4. If actual minutes are below 480, emit exactly one fingerprinted `daily_shortfall` for `480 - actual`; do not emit clock-overlap deficit fragments.
5. If actual minutes exceed 480, begin one full overtime candidate at `entry + 480 minutes`, apply transfer-tail exclusion, then split the preserved candidate by wall clock. Any candidate minute before 14:00 maps to +25%, never ordinary.
6. Emit a nonblocking schedule-entry-variation only when entry is later than 06:20 and the day otherwise reaches the ordinary quota. Acknowledgement is a separate append-only audited record and does not alter payable minutes.

Keep internal calculation helpers as implementation details rather than new public interfaces. Introduce only three new external modules where behavior genuinely varies:

- A profile activation module that computes the first future pay-period start, creates a date-effective `general` version, bulk-assigns current employees through the established lock order, and provides the sole profile resolver used by new employee creation. Date-effective selection must not depend on a scheduler running at exactly midnight.
- An append-only variation acknowledgement recorder keyed by the analysis fingerprint.
- A payroll-day snapshot writer/value object that freezes observed duration, ordinary/shortfall, complete overtime candidate and decision, variation/acknowledgement, excluded transfer minutes, actor/reason fields, and `rules_version` before exporters read them.

For partial overtime, keep the detected candidate immutable. Persist candidate start/end/minutes/rate split separately from approved start/end/minutes/rate split. Validate one contiguous approved interval within the candidate and derive its rate minutes server-side; the complement is rejected. Legacy whole APPROVED/REJECTED rows remain readable. Partial requests must not enter the batch path.

For schedule activation, add an effective-date invariant for profile versions (exact schema belongs in design), require exactly one `general` version for a company/date, and fail explicitly when no future not-started period exists. Existing profile/schedule/assignment rows and locked payroll results must not be rewritten. Additive snapshot columns/tables should leave historical rows null/legacy-labelled rather than recalculating them.

#### Test seams and strict TDD

Use one-behavior RED -> GREEN -> REFACTOR cycles through public interfaces:

- `AttendanceShiftAnalyzer::analyze(...)`: 06-14, 08-16, 09-17, 12-20, 06-16, 09-19, 12-21, Sunday/holiday, 06:20-14:20, 07:00-15:00, 06:00-16:25, and 06:00-16:31.
- `PayrollShiftEvaluator::evaluate(...)` plus recorders: 7-hour pending shortfall, GRANTED, REJECTED, REVOKED, whole overtime approval/rejection, exact contiguous partial approval, and stale fingerprints.
- Profile activation/assignment public interface: activation-date selection, locked-period rejection, prospective reassignment, concurrent/idempotent activation, and automatic selection for new employees before/on/after activation.
- `PayrollProcessor::processPayPeriod(...)`: focused blocker/snapshot/rules-version tests and proof that processed results cannot be recomputed.
- Both exporters plus `ExcelStructureTest`: all required columns, per-employee subtotals, grand totals, and minute-sum-before-hour-conversion.
- Run focused Pest tests each cycle, then canonical Pest/PHPUnit; concurrency/database invariants also need PostgreSQL-specific coverage where SQLite cannot prove locking or exclusion constraints.

#### Feature Branch Chain forecast

Delivery must start with a duplicate issue/PR search and one approved feature issue. Then create a clean sibling worktree and a draft/no-merge feature tracker. Every tracker/child PR uses exactly one type label, `type:feature`; child PR #1 targets the tracker branch and each later child targets its immediate parent. Target approximately 400 authored changed lines per child; 800 is the configured ceiling, not the normal review size.

```text
main
  `-- tracker: flexible-single-shift-payroll (draft/no-merge)
       `-- 01 duration-first quota + wall-clock bands + override tests
            `-- 02 entry tolerance + variation fact + transfer-tail tests
                 `-- 03 daily shortfall states + readiness/processor blocking
                      `-- 04 variation acknowledgement audit/review surfaces
                           `-- 05 partial overtime persistence/recorder/evaluator
                                `-- 06 effective-dated general profile activation/autoassignment
                                     `-- 07 payroll snapshot schema/writer + rules version
                                          `-- 08 global/stub report structure + subtotals/totals
                                               `-- 09 CONTEXT glossary + concise superseding ADR + final acceptance
```

Forecast: nine child PRs, mostly 250-400 authored lines. Slices 03, 05, 06, and 07 are the highest-risk estimates and must split again at persistence/domain or domain/UI seams if they approach 400-450 authored lines. Tests and migrations stay in the slice whose behavior they verify; no generated artifact justifies mixing work units.

### Risks

- Profile activation has an operational race if modeled as an `is_active` flip at midnight. Use date-effective resolution and an idempotent locked activation transaction; fail when no eligible future period exists.
- Existing `Jornadas\Index` and profile retirement code mutate availability immediately and do not implement the approved activation date. Reusing them without a dedicated activation module would assign the wrong version to employees created before activation.
- `daily_shortfall` is quota-based, not the current literal schedule-overlap segment. Reusing `starts_at`/`ends_at` as if it were observed evidence can misrepresent the domain; design must distinguish a quota deficit from a mark interval.
- Partial approval requires additive persistence that keeps both the complete candidate and selected interval. Overwriting candidate boundaries would destroy auditability and stale-decision validation.
- Existing batch overtime processing must remain whole-candidate only; otherwise one partial request could accidentally be applied as a batch-wide shape.
- Existing historical rows lack the new snapshots. Reports need explicit legacy/null behavior and must never backfill by rerunning payroll.
- Removing processor overwrite idempotence changes a tested behavior. Replace it with insert-only/idempotent receipt semantics and a regression proving locked results stay unchanged.
- SQLite cannot validate all lock-order and date-range uniqueness behavior; PostgreSQL tests are mandatory for activation and append-only constraints.
- `CONTEXT.md` and ADR 0001 currently contradict the approved policy and must be updated/superseded in the same chain.

### Ready for Proposal

Yes. The proposal should preserve the analyzer/evaluator and append-only recorder seams, define duration-first analysis and immutable reporting snapshots as the central approach, require date-effective profile activation without historical mutation, and commit to the nine-slice Feature Branch Chain with further splits above the preferred review size.
