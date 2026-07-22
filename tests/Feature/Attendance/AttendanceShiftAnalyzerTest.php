<?php

use App\Models\EmployeeScheduleAssignment;
use App\Models\RawMark;
use App\Models\WorkSchedule;
use App\Services\Attendance\AttendanceShiftAnalysis;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\ShiftOccurrence;
use Carbon\CarbonImmutable;

test('recognizes an exact scheduled shift in integer minutes', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:00:00',
    ));

    expect($analysis->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($analysis->workedMinutes)->toBe(480)
        ->and($analysis->scheduledMinutes)->toBe(480)
        ->and($analysis->deficits)->toBeEmpty()
        ->and($analysis->overtimeCandidates)->toBeEmpty();
});

test('detects a complete post-shift overtime candidate', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:30:00',
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->scheduledMinutes)->toBe(480)
        ->and($candidate->kind)->toBe('post_shift')
        ->and($candidate->start->toDateTimeString())->toBe('2026-07-20 14:00:00')
        ->and($candidate->end->toDateTimeString())->toBe('2026-07-20 14:30:00')
        ->and($candidate->minutes)->toBe(30)
        ->and($candidate->fingerprint)->toHaveLength(64)
        ->and($candidate->key)->toHaveLength(64);
});

test('keeps pre-shift and post-shift candidates as separate decisions', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 05:30:00',
        exitAt: '2026-07-20 14:30:00',
    ));

    expect($analysis->overtimeCandidates)->toHaveCount(2)
        ->and($analysis->overtimeCandidates->pluck('kind')->all())->toBe(['pre_shift', 'post_shift'])
        ->and($analysis->overtimeCandidates->pluck('minutes')->all())->toBe([30, 30])
        ->and($analysis->overtimeCandidates->pluck('key')->unique())->toHaveCount(2);
});

test('reports late arrival and early departure as exact scheduled deficits', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:15:00',
        exitAt: '2026-07-20 13:45:00',
    ));

    expect($analysis->workedMinutes)->toBe(450)
        ->and($analysis->scheduledMinutes)->toBe(450)
        ->and($analysis->deficits->pluck('kind')->all())->toBe(['late_arrival', 'early_departure'])
        ->and($analysis->deficits->pluck('minutes')->all())->toBe([15, 15])
        ->and($analysis->overtimeCandidates)->toBeEmpty();
});

test('treats the whole observed interval on a non-working date as one candidate', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-19',
        entryAt: '2026-07-19 10:00:00',
        exitAt: '2026-07-19 12:00:00',
        scheduledStart: null,
        scheduledEnd: null,
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->workedMinutes)->toBe(120)
        ->and($analysis->scheduledMinutes)->toBe(0)
        ->and($analysis->deficits)->toBeEmpty()
        ->and($candidate->kind)->toBe('non_working')
        ->and($candidate->minutes)->toBe(120);
});

test('recognizes an overnight scheduled interval on its starting work date', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 18:00:00',
        exitAt: '2026-07-21 06:00:00',
        scheduledStart: '18:00',
        scheduledEnd: '06:00',
    ));

    expect($analysis->workedMinutes)->toBe(720)
        ->and($analysis->scheduledMinutes)->toBe(720)
        ->and($analysis->deficits)->toBeEmpty()
        ->and($analysis->overtimeCandidates)->toBeEmpty();
});

test('propagates an unresolved occurrence without inventing observed time', function () {
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

    expect($analysis->status)->toBe(ShiftOccurrence::AMBIGUOUS)
        ->and($analysis->entryAt)->toBeNull()
        ->and($analysis->exitAt)->toBeNull()
        ->and($analysis->workedMinutes)->toBe(0)
        ->and($analysis->scheduledMinutes)->toBe(0)
        ->and($analysis->deficits)->toBeEmpty()
        ->and($analysis->overtimeCandidates)->toBeEmpty();
});

test('blocks a resolved pair whose timestamps do not form an interval', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 06:00:00',
    ));

    expect($analysis->status)->toBe(AttendanceShiftAnalysis::INVALID_INTERVAL)
        ->and($analysis->entryAt?->toDateTimeString())->toBe('2026-07-20 06:00:00')
        ->and($analysis->exitAt?->toDateTimeString())->toBe('2026-07-20 06:00:00')
        ->and($analysis->workedMinutes)->toBe(0)
        ->and($analysis->overtimeCandidates)->toBeEmpty();
});

function attendanceOccurrence(
    string $workDate,
    string $entryAt,
    string $exitAt,
    ?string $scheduledStart = '06:00',
    ?string $scheduledEnd = '14:00',
): ShiftOccurrence {
    $date = CarbonImmutable::parse($workDate)->startOfDay();
    $schedule = (new WorkSchedule)->forceFill([
        'id' => 10,
        'work_schedule_profile_id' => 20,
        'is_working_day' => $scheduledStart !== null,
        'start_time' => $scheduledStart,
        'end_time' => $scheduledEnd,
    ]);
    $assignment = (new EmployeeScheduleAssignment)->forceFill([
        'id' => 30,
        'work_schedule_profile_id' => 20,
    ]);
    $entry = (new RawMark)->forceFill(['id' => 40, 'event_at' => $entryAt, 'status' => 'valid']);
    $exit = (new RawMark)->forceFill(['id' => 41, 'event_at' => $exitAt, 'status' => 'valid']);
    $start = $scheduledStart === null ? null : $date->setTimeFromTimeString($scheduledStart);
    $end = $scheduledEnd === null ? null : $date->setTimeFromTimeString($scheduledEnd);

    if ($start !== null && $end?->lte($start)) {
        $end = $end->addDay();
    }

    return new ShiftOccurrence(
        $date,
        $assignment,
        $schedule,
        $start,
        $end,
        collect([$entry, $exit]),
        ShiftOccurrence::RESOLVED,
    );
}
