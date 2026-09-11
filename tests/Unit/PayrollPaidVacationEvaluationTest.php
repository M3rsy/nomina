<?php

use App\Models\VacationDay;
use App\Services\Attendance\AttendanceDecisionMatcher;
use App\Services\Attendance\AttendanceShiftAnalysis;
use App\Services\Attendance\PayrollShiftEvaluation;
use App\Services\Attendance\PayrollShiftEvaluator;
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Payroll\BandSplit;
use Carbon\CarbonImmutable;

test('a current vacation day pays planned bands without treating the employee as absent', function () {
    [$occurrence, $analysis] = paidVacationEvaluationContext();

    $evaluation = (new PayrollShiftEvaluator(new AttendanceDecisionMatcher))->evaluate(
        $occurrence,
        $analysis,
        collect(),
        collect(),
        paidVacationDay(),
    );

    expect($evaluation->status)->toBe(PayrollShiftEvaluation::PROCESSABLE)
        ->and($evaluation->dayType)->toBe(PayrollShiftEvaluation::DAY_TYPE_PAID_VACATION)
        ->and($evaluation->workedMinutes)->toBe(0)
        ->and($evaluation->scheduledMinutes)->toBe(480)
        ->and($evaluation->recognizedMinutes)->toBe(480)
        ->and($evaluation->payableRates->ordinaryMinutes)->toBe(420)
        ->and($evaluation->payableRates->extra25Minutes)->toBe(60)
        ->and($evaluation->isAbsence)->toBeFalse()
        ->and($evaluation->isJustified)->toBeFalse()
        ->and($evaluation->unjustified)->toBeFalse()
        ->and($evaluation->vacationId)->toBe(50)
        ->and($evaluation->vacationDayId)->toBe(51);
});

test('marks recorded on a vacation day block payroll', function () {
    [$occurrence, $analysis] = paidVacationEvaluationContext(withMark: true);

    $evaluation = (new PayrollShiftEvaluator(new AttendanceDecisionMatcher))->evaluate(
        $occurrence,
        $analysis,
        collect(),
        collect(),
        paidVacationDay(),
    );

    expect($evaluation->status)->toBe(PayrollShiftEvaluation::BLOCKED)
        ->and($evaluation->blockers->sole())->toBe([
            'code' => 'vacation_has_marks',
            'vacation_day_id' => 51,
        ]);
});

test('a vacation captured against an obsolete schedule blocks payroll', function () {
    [$occurrence, $analysis] = paidVacationEvaluationContext();

    $evaluation = (new PayrollShiftEvaluator(new AttendanceDecisionMatcher))->evaluate(
        $occurrence,
        $analysis,
        collect(),
        collect(),
        paidVacationDay(),
        true,
    );

    expect($evaluation->status)->toBe(PayrollShiftEvaluation::BLOCKED)
        ->and($evaluation->blockers->sole())->toBe([
            'code' => 'stale_vacation_day',
            'vacation_day_id' => 51,
        ]);
});

/** @return array{ShiftOccurrence, AttendanceShiftAnalysis} */
function paidVacationEvaluationContext(bool $withMark = false): array
{
    $date = CarbonImmutable::parse('2026-09-10')->startOfDay();
    $marks = $withMark
        ? collect([(object) ['id' => 70, 'event_at' => $date->addHours(8)]])
        : collect();
    $occurrence = new ShiftOccurrence(
        workDate: $date,
        assignment: null,
        schedule: null,
        scheduledStart: $date->addHours(8),
        scheduledEnd: $date->addHours(16),
        marks: $marks,
        status: $withMark ? ShiftOccurrence::MISSING_PAIR : ShiftOccurrence::NO_MARKS,
    );
    $analysis = new AttendanceShiftAnalysis(
        status: $occurrence->status,
        workDate: $date,
        entryAt: $withMark ? $date->addHours(8) : null,
        exitAt: null,
        workedMinutes: 0,
        scheduledMinutes: 480,
        scheduledRates: new BandSplit(ordinaryMinutes: 480),
        deficits: collect(),
        overtimeCandidates: collect(),
    );

    return [$occurrence, $analysis];
}

function paidVacationDay(): VacationDay
{
    return (new VacationDay)->forceFill([
        'id' => 51,
        'vacation_id' => 50,
        'planned_minutes' => 480,
        'rate_minutes' => [
            'ordinary' => 420,
            'extra25' => 60,
            'extra50' => 0,
            'extra75' => 0,
            'extra100' => 0,
        ],
        'snapshot_fingerprint' => str_repeat('a', 64),
    ]);
}
