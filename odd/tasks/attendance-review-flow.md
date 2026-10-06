# Attendance Review Flow

## Goal
Create a pre-payroll attendance review flow for GL001/GLG uploads without rewriting working payroll functionality.

## Current architecture findings
- `.txt` uploads are parsed as GLG format by `App\Services\Parsers\GlgParser`; employee external ID comes from column 2 and timestamp from column 6.
- Current duplicate mark key is `employee_external_id|event_at->toDateTimeString()` in `App\Services\FileValidator`.
- Source evidence is stored in `raw_marks`; source fields are immutable and records are logically deleted by status, not physically deleted.
- Attendance review already uses `ShiftOccurrenceResolver`, `AttendanceShiftAnalyzer`, `PayrollShiftEvaluator`, `PayrollReadinessChecker`, `AttendanceExceptionRecorder`, and `OvertimeDecisionRecorder`.
- Overtime approval and attendance exception flows already exist and are append-only/audited.
- Saturday is already modeled as a 4-hour working schedule in the default company/profile seed, but `duration-first-v2` currently uses an 8-hour ordinary quota for Monday-Saturday.

## Business rule clarification
- Monday-Friday: evaluate by total worked duration, not strict schedule overlap. If the employee enters late but still works 8 hours, the day is complete and should not create a missing-hours discount. If the employee works fewer than 8 hours, only the missing duration is deficit. If worked duration exceeds 8 hours, only the excess is overtime.
- Saturday: total recognized day is 8 hours, split as 4 hours physically worked by the employee plus 4 hours provided/recognized by labor law. If physical work is less than 4 hours, missing hours are `4h - worked`. If physical work exceeds 4 hours, the excess is potential overtime. The 4 legal/government hours are not extra and do not require a clock mark.
- Sunday: free day. Any worked time is overtime at 100%.
- Consequence: the target policy for this feature is duration-first for attendance review and payroll readiness, with a Saturday-specific physical-work quota of 4 hours and Sunday extra100 behavior.

## Implementation tasks

- [x] Add duplicate review reader/service with grouped duplicate summaries and keep/delete preview.
- [x] Add controlled duplicate resolution action that preserves one valid mark, marks extras as deleted, records metadata revision/audit, advances fact generations, and updates upload summary.
- [x] Improve the bounded attendance review UI with explicit duplicate cards, employee counts, duplicate grouping, compact preview, and bulk confirmation.
- [x] Add attendance review summary projection for employee/day cards: required minutes, worked minutes, missing minutes, overtime minutes, absence/incomplete/ambiguous status.
- [x] Add bounded `nomina.revisar` dashboard sections for overall review counters, Duplicates, Jornada laboral, Faltas/Horas extras through existing panels, and blocking alerts. Full tabbed wizard remains a later UX refinement.
- [x] Tighten readiness/confirmation so unresolved critical incidents block accidental payroll processing. The bounded gate now blocks pending, unknown-employee, out-of-period, invalid, and duplicate raw/import incidents plus all existing payroll readiness blockers; only non-critical upload warnings retain confirmation.
- [x] Verify Saturday behavior against existing policies; adjust conservatively only where current engine contradicts required 4 worked + 4 government semantics. (Bounded duration-first attendance slice; duplicate review remains out of scope.)
- [x] Add regression tests for duplicate detection/resolution, weekday deficits/overtime, Saturday, absences/incomplete marks, and reprocessing safety.

Readiness-gating regression coverage now includes critical raw/import statuses, unresolved duplicate groups, confirmation-bypass attempts, and direct `StartPayrollProcessing` calls against an already-ready period in `tests/Feature/Nomina/AttendanceReviewTest.php`.

Post-static-review fixes added duplicate-resolution guards for mixed critical/unassigned groups and aligned Saturday duration-first attendance summaries to 480 required minutes. Two read-only static verifications found no remaining high/medium/low issues.

Current bounded slice: duration-first rules, duplicate grouping/logical resolution, attendance summary cards, review counters, and critical readiness gating are implemented. Runtime verification passed through the running `lerd-php85-fpm` container: focused duplicate/readiness/Saturday tests passed (16 tests, 66 assertions), and related analyzer/policy tests passed (65 tests, 365 assertions).

## Constraints
- Do not rewrite existing working payroll engine.
- Reuse the resolver/analyzer/evaluator/decision architecture.
- Do not invent monetary deductions; current system deducts/reinstates recognized time, not net pay.
- Do not physically delete `raw_marks`; use logical status plus audit metadata.
- No commits unless the user explicitly requests them.
