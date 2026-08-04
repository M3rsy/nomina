<?php

use App\Models\AttendanceException;
use App\Models\EmployeeScheduleAssignment;
use App\Models\OvertimeDecision;
use App\Models\RawMark;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\AttendanceSegment;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\PayrollShiftEvaluator;
use App\Services\Attendance\ShiftOccurrence;
use Carbon\CarbonImmutable;

test('automatically recognizes exact scheduled time at its configured rates', function () {
    [$occurrence, $analysis] = payrollShift(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 18:00:00',
        exitAt: '2026-07-21 06:00:00',
        scheduledStart: '18:00',
        scheduledEnd: '06:00',
    );

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect());

    expect($evaluation->status)->toBe('processable')
        ->and($evaluation->workedMinutes)->toBe(720)
        ->and($evaluation->scheduledMinutes)->toBe(720)
        ->and($evaluation->recognizedMinutes)->toBe(720)
        ->and($evaluation->payableRates->extra50Minutes)->toBe(360)
        ->and($evaluation->payableRates->extra75Minutes)->toBe(360)
        ->and($evaluation->blockers)->toBeEmpty();
});

test('blocks payroll while a complete overtime candidate has no decision', function () {
    [$occurrence, $analysis] = payrollShift(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:30:00',
    );
    $candidate = $analysis->overtimeCandidates->sole();

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect());

    expect($evaluation->status)->toBe('blocked')
        ->and($evaluation->scheduledMinutes)->toBe(480)
        ->and($evaluation->recognizedMinutes)->toBe(480)
        ->and($evaluation->detectedOvertimeMinutes)->toBe(30)
        ->and($evaluation->approvedOvertimeMinutes)->toBe(0)
        ->and($evaluation->blockers->sole())->toBe([
            'code' => 'pending_overtime_candidate',
            'candidate_key' => $candidate->key,
        ]);
});

test('adds an approved complete candidate without rounding its minutes', function () {
    [$occurrence, $analysis] = payrollShift(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:30:00',
    );
    $candidate = $analysis->overtimeCandidates->sole();
    $decision = payrollDecision($candidate, OvertimeDecision::APPROVED);

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect([$decision]));

    expect($evaluation->status)->toBe('processable')
        ->and($evaluation->recognizedMinutes)->toBe(510)
        ->and($evaluation->detectedOvertimeMinutes)->toBe(30)
        ->and($evaluation->approvedOvertimeMinutes)->toBe(30)
        ->and($evaluation->payableRates->ordinaryMinutes)->toBe(480)
        ->and($evaluation->payableRates->extra25Minutes)->toBe(30)
        ->and($evaluation->payableRates->extra25Hours())->toBe(0.5);
});

test('records rejected candidate time as reviewed but not payable', function () {
    [$occurrence, $analysis] = payrollShift(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:30:00',
    );
    $candidate = $analysis->overtimeCandidates->sole();

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate(
        $occurrence,
        $analysis,
        collect([payrollDecision($candidate, OvertimeDecision::REJECTED)]),
    );

    expect($evaluation->status)->toBe('processable')
        ->and($evaluation->recognizedMinutes)->toBe(480)
        ->and($evaluation->detectedOvertimeMinutes)->toBe(30)
        ->and($evaluation->approvedOvertimeMinutes)->toBe(0)
        ->and($evaluation->payableRates->extra25Minutes)->toBe(0)
        ->and($evaluation->blockers)->toBeEmpty();
});

test('credits only an exact granted attendance deficit', function () {
    [$occurrence, $analysis] = payrollShift(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:15:00',
        exitAt: '2026-07-20 14:00:00',
    );
    $deficit = $analysis->deficits->sole();
    $evaluator = app(PayrollShiftEvaluator::class);
    $withoutException = $evaluator->evaluate($occurrence, $analysis, collect());
    $granted = payrollAttendanceException($deficit, AttendanceException::GRANTED);
    $withException = $evaluator->evaluate($occurrence, $analysis, collect(), collect([$granted]));

    expect($withoutException->scheduledMinutes)->toBe(480)
        ->and($withoutException->recognizedMinutes)->toBe(465)
        ->and($withoutException->excusedDeficitMinutes)->toBe(0)
        ->and($withException->scheduledMinutes)->toBe(480)
        ->and($withException->recognizedMinutes)->toBe(480)
        ->and($withException->excusedDeficitMinutes)->toBe(15)
        ->and($withException->payableRates->ordinaryMinutes)->toBe(480)
        ->and($withException->metadata)->toBe([
            'attendance_exception_ids' => [42],
            'excused_deficit_minutes' => 15,
        ]);

    $granted->fingerprint = str_repeat('f', 64);
    $stale = $evaluator->evaluate($occurrence, $analysis, collect(), collect([$granted]));
    $revoked = $evaluator->evaluate(
        $occurrence,
        $analysis,
        collect(),
        collect([payrollAttendanceException($deficit, AttendanceException::REVOKED)]),
    );

    expect($stale->recognizedMinutes)->toBe(465)
        ->and($revoked->recognizedMinutes)->toBe(465);
});

test('blocks unresolved attendance instead of inventing payable time', function () {
    $occurrence = new ShiftOccurrence(
        CarbonImmutable::parse('2026-07-20'),
        null,
        null,
        null,
        null,
        collect(),
        ShiftOccurrence::AMBIGUOUS,
    );
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze($occurrence);

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect());

    expect($evaluation->status)->toBe('blocked')
        ->and($evaluation->recognizedMinutes)->toBe(0)
        ->and($evaluation->blockers->sole())->toBe(['code' => ShiftOccurrence::AMBIGUOUS]);
});

test('keeps a scheduled day with no marks as an unpaid absence', function () {
    [$occurrence, $analysis] = payrollShiftWithoutMarks();

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect());

    expect($evaluation->status)->toBe('processable')
        ->and($evaluation->isAbsence)->toBeTrue()
        ->and($evaluation->isJustified)->toBeFalse()
        ->and($evaluation->unjustified)->toBeTrue()
        ->and($evaluation->recognizedMinutes)->toBe(0);
});

test('blocks a duration-first no-mark day with one pending daily shortfall', function () {
    [$occurrence, $analysis] = payrollShiftWithoutMarks(
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    );

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect());
    $deficit = $analysis->deficits->sole();

    expect($deficit->kind)->toBe('daily_shortfall')
        ->and($deficit->minutes)->toBe(480)
        ->and($deficit->start)->toBeNull()
        ->and($deficit->end)->toBeNull()
        ->and($evaluation->status)->toBe('blocked')
        ->and($evaluation->recognizedMinutes)->toBe(0)
        ->and($evaluation->blockers->sole()['code'])->toBe('pending_daily_shortfall');
});

test('credits a scheduled no-mark shift through an append-only attendance exception', function () {
    [$occurrence, $analysis] = payrollShiftWithoutMarks();
    $deficit = $analysis->deficits->sole();
    $exception = payrollAttendanceException($deficit, AttendanceException::GRANTED);

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate(
        $occurrence,
        $analysis,
        collect(),
        collect([$exception]),
    );

    expect($deficit->kind)->toBe('full_day_absence')
        ->and($deficit->minutes)->toBe(480)
        ->and($evaluation->status)->toBe('processable')
        ->and($evaluation->isAbsence)->toBeTrue()
        ->and($evaluation->isJustified)->toBeTrue()
        ->and($evaluation->unjustified)->toBeFalse()
        ->and($evaluation->scheduledMinutes)->toBe(480)
        ->and($evaluation->recognizedMinutes)->toBe(480)
        ->and($evaluation->payableRates->ordinaryMinutes)->toBe(480);
});

test('keeps a revoked full-day attendance exception unpaid', function () {
    [$occurrence, $analysis] = payrollShiftWithoutMarks();
    $deficit = $analysis->deficits->sole();
    $exception = payrollAttendanceException($deficit, AttendanceException::REVOKED);

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect(), collect([$exception]));

    expect($evaluation->isJustified)->toBeFalse()
        ->and($evaluation->recognizedMinutes)->toBe(0);
});

test('preserves scheduled rate bands for a granted overnight full-day exception', function () {
    [$occurrence, $analysis] = payrollShiftWithoutMarks(
        scheduledStart: '18:00',
        scheduledEnd: '06:00',
    );
    $deficit = $analysis->deficits->sole();
    $exception = payrollAttendanceException($deficit, AttendanceException::GRANTED);

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect(), collect([$exception]));

    expect($evaluation->status)->toBe('processable')
        ->and($evaluation->scheduledMinutes)->toBe(720)
        ->and($evaluation->recognizedMinutes)->toBe(720)
        ->and($evaluation->payableRates->ordinaryMinutes)->toBe(0)
        ->and($evaluation->payableRates->extra50Minutes)->toBe(360)
        ->and($evaluation->payableRates->extra75Minutes)->toBe(360);
});

test('skips a non-working date with no observed marks', function () {
    [$occurrence, $analysis] = payrollShiftWithoutMarks(isWorkingDay: false);

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect());

    expect($evaluation->status)->toBe('skip')
        ->and($evaluation->isAbsence)->toBeFalse()
        ->and($evaluation->recognizedMinutes)->toBe(0);
});

test('keeps V2 overtime pending until approval then recognizes the exact candidate bands', function () {
    [$occurrence, $analysis] = payrollShift(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 09:00:00',
        exitAt: '2026-07-20 19:00:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    );
    $candidate = $analysis->overtimeCandidates->sole();
    $evaluator = app(PayrollShiftEvaluator::class);

    $pendingEvaluation = $evaluator->evaluate($occurrence, $analysis, collect());
    $approvedEvaluation = $evaluator->evaluate(
        $occurrence,
        $analysis,
        collect([payrollDecision($candidate, OvertimeDecision::APPROVED)]),
    );

    expect($pendingEvaluation->status)->toBe('blocked')
        ->and($pendingEvaluation->recognizedMinutes)->toBe(480)
        ->and($pendingEvaluation->detectedOvertimeMinutes)->toBe(120)
        ->and($pendingEvaluation->approvedOvertimeMinutes)->toBe(0)
        ->and($pendingEvaluation->blockers)->toHaveCount(1)
        ->and($pendingEvaluation->blockers->sole()['code'])->toBe('pending_overtime_candidate')
        ->and($approvedEvaluation->status)->toBe('processable')
        ->and($approvedEvaluation->recognizedMinutes)->toBe(600)
        ->and($approvedEvaluation->detectedOvertimeMinutes)->toBe(120)
        ->and($approvedEvaluation->approvedOvertimeMinutes)->toBe(120)
        ->and($approvedEvaluation->blockers)->toBeEmpty()
        ->and($approvedEvaluation->payableRates->ordinaryMinutes)->toBe(480)
        ->and($approvedEvaluation->payableRates->extra25Minutes)->toBe(60)
        ->and($approvedEvaluation->payableRates->extra50Minutes)->toBe(60)
        ->and($approvedEvaluation->payrollPolicyKey)->toBe('duration-first-v2');
});

test('recognizes only the conserved approved bands from a partial V2 decision', function () {
    [$occurrence, $analysis] = payrollShift(
        workDate: '2026-07-20', entryAt: '2026-07-20 09:00:00', exitAt: '2026-07-20 19:00:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    );
    $candidate = $analysis->overtimeCandidates->sole();
    $decision = payrollDecision($candidate, OvertimeDecision::APPROVED, [
        'record_version' => 2, 'resolution_kind' => 'partial', 'resolution_hash' => str_repeat('a', 64),
        'approved_minutes' => 60, 'rejected_minutes' => 60,
        'approved_rate_minutes' => ['ordinary' => 0, 'extra25' => 30, 'extra50' => 30, 'extra75' => 0, 'extra100' => 0],
        'rejected_rate_minutes' => ['ordinary' => 0, 'extra25' => 30, 'extra50' => 30, 'extra75' => 0, 'extra100' => 0],
    ]);

    $evaluation = app(PayrollShiftEvaluator::class)->evaluate($occurrence, $analysis, collect([$decision]));

    expect($evaluation->status)->toBe('processable')
        ->and($evaluation->recognizedMinutes)->toBe(540)
        ->and($evaluation->approvedOvertimeMinutes)->toBe(60)
        ->and($evaluation->payableRates->extra25Minutes)->toBe(30)
        ->and($evaluation->payableRates->extra50Minutes)->toBe(30);
});

function payrollShift(
    string $workDate,
    string $entryAt,
    string $exitAt,
    ?string $scheduledStart = '06:00',
    ?string $scheduledEnd = '14:00',
    string $payrollPolicyKey = WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1,
): array {
    $date = CarbonImmutable::parse($workDate)->startOfDay();
    $schedule = (new WorkSchedule)->forceFill([
        'id' => 10,
        'is_working_day' => $scheduledStart !== null,
        'base_ordinary_hours' => 8,
        'start_time' => $scheduledStart,
        'end_time' => $scheduledEnd,
    ]);
    $assignment = (new EmployeeScheduleAssignment)->forceFill(['id' => 20]);
    $entry = (new RawMark)->forceFill(['id' => 30, 'event_at' => $entryAt, 'status' => 'valid']);
    $exit = (new RawMark)->forceFill(['id' => 31, 'event_at' => $exitAt, 'status' => 'valid']);
    $start = $scheduledStart === null ? null : $date->setTimeFromTimeString($scheduledStart);
    $end = $scheduledEnd === null ? null : $date->setTimeFromTimeString($scheduledEnd);

    if ($start !== null && $end?->lte($start)) {
        $end = $end->addDay();
    }

    $occurrence = new ShiftOccurrence(
        $date,
        $assignment,
        $schedule,
        $start,
        $end,
        collect([$entry, $exit]),
        ShiftOccurrence::RESOLVED,
        payrollPolicyKey: $payrollPolicyKey,
    );

    return [$occurrence, app(AttendanceShiftAnalyzer::class)->analyze($occurrence)];
}

function payrollDecision(AttendanceSegment $candidate, string $decision, array $resolution = []): OvertimeDecision
{
    return (new OvertimeDecision)->forceFill([
        'candidate_key' => $candidate->key,
        'fingerprint' => $candidate->fingerprint,
        'starts_at' => $candidate->start,
        'ends_at' => $candidate->end,
        'minutes' => $candidate->minutes,
        'rate_minutes' => [
            'ordinary' => $candidate->rateMinutes->ordinaryMinutes,
            'extra25' => $candidate->rateMinutes->extra25Minutes,
            'extra50' => $candidate->rateMinutes->extra50Minutes,
            'extra75' => $candidate->rateMinutes->extra75Minutes,
            'extra100' => $candidate->rateMinutes->extra100Minutes,
        ],
        'decision' => $decision,
        ...$resolution,
    ]);
}

function payrollAttendanceException(AttendanceSegment $deficit, string $decision): AttendanceException
{
    return (new AttendanceException)->forceFill([
        'id' => 42,
        'deficit_key' => $deficit->key,
        'fingerprint' => $deficit->fingerprint,
        'starts_at' => $deficit->start,
        'ends_at' => $deficit->end,
        'minutes' => $deficit->minutes,
        'rate_minutes' => [
            'ordinary' => $deficit->rateMinutes->ordinaryMinutes,
            'extra25' => $deficit->rateMinutes->extra25Minutes,
            'extra50' => $deficit->rateMinutes->extra50Minutes,
            'extra75' => $deficit->rateMinutes->extra75Minutes,
            'extra100' => $deficit->rateMinutes->extra100Minutes,
        ],
        'decision' => $decision,
    ]);
}

function payrollShiftWithoutMarks(
    bool $isWorkingDay = true,
    string $scheduledStart = '06:00',
    string $scheduledEnd = '14:00',
    string $payrollPolicyKey = WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1,
): array {
    $date = CarbonImmutable::parse('2026-07-20')->startOfDay();
    $schedule = (new WorkSchedule)->forceFill([
        'id' => 10,
        'is_working_day' => $isWorkingDay,
        'base_ordinary_hours' => 8,
        'start_time' => $scheduledStart,
        'end_time' => $scheduledEnd,
    ]);
    $start = $date->setTimeFromTimeString($scheduledStart);
    $end = $date->setTimeFromTimeString($scheduledEnd);

    if ($end->lte($start)) {
        $end = $end->addDay();
    }

    $occurrence = new ShiftOccurrence(
        $date,
        (new EmployeeScheduleAssignment)->forceFill(['id' => 20]),
        $schedule,
        $start,
        $end,
        collect(),
        ShiftOccurrence::NO_MARKS,
        payrollPolicyKey: $payrollPolicyKey,
    );

    return [$occurrence, app(AttendanceShiftAnalyzer::class)->analyze($occurrence)];
}
