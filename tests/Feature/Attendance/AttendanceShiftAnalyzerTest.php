<?php

use App\Models\EmployeeScheduleAssignment;
use App\Models\RawMark;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfilePublication;
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
        ->and($analysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($analysis->scheduledRates->totalMinutes())->toBe(480)
        ->and($analysis->deficits)->toBeEmpty()
        ->and($analysis->overtimeCandidates)->toBeEmpty();
});

test('conserves scheduled minutes when both attendance marks contain seconds', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:30',
        exitAt: '2026-07-20 14:00:30',
    ));

    expect($analysis->entryAt?->toDateTimeString())->toBe('2026-07-20 06:00:30')
        ->and($analysis->exitAt?->toDateTimeString())->toBe('2026-07-20 14:00:30')
        ->and($analysis->workedMinutes)->toBe(480)
        ->and($analysis->scheduledMinutes)->toBe(480)
        ->and($analysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($analysis->deficits)->toBeEmpty()
        ->and($analysis->overtimeCandidates)->toBeEmpty();
});

test('partitions second-bearing marks once into scheduled and candidate minutes', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:30',
        exitAt: '2026-07-20 14:30:30',
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->workedMinutes)->toBe(510)
        ->and($analysis->scheduledMinutes)->toBe(480)
        ->and($analysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($candidate->start->toDateTimeString())->toBe('2026-07-20 14:00:30')
        ->and($candidate->end->toDateTimeString())->toBe('2026-07-20 14:30:30')
        ->and($candidate->minutes)->toBe(30)
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(30)
        ->and($analysis->scheduledMinutes + $candidate->minutes)->toBe($analysis->workedMinutes);
});

test('never creates an unworked minute from second-bearing marks', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 14:00:59',
        exitAt: '2026-07-20 14:30:00',
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->workedMinutes)->toBe(29)
        ->and($analysis->scheduledMinutes)->toBe(0)
        ->and($candidate->start->toDateTimeString())->toBe('2026-07-20 14:00:59')
        ->and($candidate->end->toDateTimeString())->toBe('2026-07-20 14:29:59')
        ->and($candidate->minutes)->toBe(29)
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(29);
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
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(30)
        ->and($candidate->rateMinutes->extra25Hours())->toBe(0.5)
        ->and($candidate->rateMinutes->ordinaryMinutes)->toBe(0)
        ->and($candidate->fingerprint)->toHaveLength(64)
        ->and($candidate->key)->toHaveLength(64);
});

test('classifies a six to seventeen shift as eight ordinary hours and three candidate hours at twenty five percent', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 17:00:00',
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->scheduledMinutes)->toBe(480)
        ->and($analysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($candidate->kind)->toBe('post_shift')
        ->and($candidate->minutes)->toBe(180)
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(180);
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
        ->and($analysis->deficits->pluck('rateMinutes.ordinaryMinutes')->all())->toBe([15, 15])
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
        ->and($candidate->minutes)->toBe(120)
        ->and($candidate->rateMinutes->extra100Minutes)->toBe(120);
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
        ->and($analysis->scheduledRates->extra50Minutes)->toBe(360)
        ->and($analysis->scheduledRates->extra75Minutes)->toBe(360)
        ->and($analysis->scheduledRates->totalMinutes())->toBe(720)
        ->and($analysis->deficits)->toBeEmpty()
        ->and($analysis->overtimeCandidates)->toBeEmpty();
});

test('classifies saturday scheduled time and its complete post-shift candidate separately', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-18',
        entryAt: '2026-07-18 08:00:00',
        exitAt: '2026-07-18 13:00:00',
        scheduledStart: '08:00',
        scheduledEnd: '12:00',
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->scheduledRates->ordinaryMinutes)->toBe(240)
        ->and($candidate->minutes)->toBe(60)
        ->and($candidate->rateMinutes->ordinaryMinutes)->toBe(0)
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(60);
});

test('classifies scheduled work on a holiday at one hundred percent', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:00:00',
    ), isHoliday: true);

    expect($analysis->scheduledRates->ordinaryMinutes)->toBe(0)
        ->and($analysis->scheduledRates->extra100Minutes)->toBe(480)
        ->and($analysis->scheduledRates->totalMinutes())->toBe(480);
});

test('changes candidate identity when holiday classification changes', function () {
    $occurrence = attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:30:00',
    );
    $regular = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $holiday = app(AttendanceShiftAnalyzer::class)->analyze($occurrence, isHoliday: true)->overtimeCandidates->sole();

    expect($holiday->key)->not->toBe($regular->key)
        ->and($holiday->rateMinutes->extra100Minutes)->toBe(30);
});

test('changes candidate identity when the attendance fact generation advances', function () {
    $original = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:30:00',
        factGeneration: 0,
    ))->overtimeCandidates->sole();
    $changed = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:30:00',
        factGeneration: 1,
    ))->overtimeCandidates->sole();

    expect($changed->key)->not->toBe($original->key)
        ->and($changed->fingerprint)->not->toBe($original->fingerprint);
});

test('ignores historical custom schedule bands in prospective calculations', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 08:00:00',
        exitAt: '2026-07-20 12:00:00',
        scheduledStart: '08:00',
        scheduledEnd: '12:00',
        bands: [
            ['start' => '00:00', 'end' => '08:00', 'rate' => 75],
            ['start' => '08:00', 'end' => '10:00', 'rate' => 25],
            ['start' => '10:00', 'end' => '12:00', 'rate' => 50],
            ['start' => '12:00', 'end' => '00:00', 'rate' => 0],
        ],
    ));

    expect($analysis->scheduledRates->ordinaryMinutes)->toBe(240)
        ->and($analysis->scheduledRates->extra25Minutes)->toBe(0)
        ->and($analysis->scheduledRates->extra50Minutes)->toBe(0)
        ->and($analysis->scheduledRates->totalMinutes())->toBe(240);
});

test('ignores incomplete historical rate bands and uses canonical coverage', function () {
    $occurrence = attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:00:00',
        bands: [
            ['start' => '00:00', 'end' => '06:00', 'rate' => 75],
            ['start' => '06:00', 'end' => '12:00', 'rate' => 0],
            ['start' => '14:00', 'end' => '00:00', 'rate' => 50],
        ],
    );
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze($occurrence);
    expect($analysis->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($analysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($analysis->scheduledRates->totalMinutes())->toBe(480);
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

test('splits duration-first post-quota time at the eighteen hundred wall-clock boundary', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 09:30:00',
        exitAt: '2026-07-20 18:30:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->workedMinutes)->toBe(540)
        ->and($analysis->scheduledMinutes)->toBe(480)
        ->and($analysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($candidate->kind)->toBe('post_quota_overtime')
        ->and($candidate->start->toDateTimeString())->toBe('2026-07-20 17:30:00')
        ->and($candidate->end->toDateTimeString())->toBe('2026-07-20 18:30:00')
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(30)
        ->and($candidate->rateMinutes->extra50Minutes)->toBe(30)
        ->and($candidate->rateMinutes->totalMinutes())->toBe(60);
});

test('keeps exactly 480 whole minutes for a 480 minute 59 second duration-first interval', function () {
    $occurrence = attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:00:59',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    );
    $analyzer = app(AttendanceShiftAnalyzer::class);

    $firstAnalysis = $analyzer->analyze($occurrence);
    $repeatedAnalysis = $analyzer->analyze($occurrence);

    expect($firstAnalysis->workedMinutes)->toBe(480)
        ->and($firstAnalysis->scheduledMinutes)->toBe(480)
        ->and($firstAnalysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($firstAnalysis->overtimeCandidates)->toBeEmpty()
        ->and($repeatedAnalysis->workedMinutes)->toBe(480)
        ->and($repeatedAnalysis->scheduledRates->totalMinutes())->toBe(480)
        ->and($occurrence->entryMark()?->event_at->toDateTimeString())->toBe('2026-07-20 06:00:00')
        ->and($occurrence->exitMark()?->event_at->toDateTimeString())->toBe('2026-07-20 14:00:59')
        ->and($occurrence->entryMark()?->metadata['revisions'])->toBe([['revision' => 1]])
        ->and($occurrence->exitMark()?->metadata['revisions'])->toBe([['revision' => 2]]);
});

test('keeps shifted duration-first eight-hour days at 480 ordinary minutes', function (string $entryAt, string $exitAt) {
    $shiftAnalysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: $entryAt,
        exitAt: $exitAt,
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));

    expect($shiftAnalysis->workedMinutes)->toBe(480)
        ->and($shiftAnalysis->scheduledMinutes)->toBe(480)
        ->and($shiftAnalysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($shiftAnalysis->scheduledRates->totalMinutes())->toBe(480)
        ->and($shiftAnalysis->deficits)->toBeEmpty()
        ->and($shiftAnalysis->overtimeCandidates)->toBeEmpty();
})->with([
    '08:00-16:00' => ['2026-07-20 08:00:00', '2026-07-20 16:00:00'],
    '09:00-17:00' => ['2026-07-20 09:00:00', '2026-07-20 17:00:00'],
    '12:00-20:00' => ['2026-07-20 12:00:00', '2026-07-20 20:00:00'],
]);

test('allocates each duration-first post-quota worked example by wall clock', function (
    string $entryAt,
    string $exitAt,
    string $candidateStart,
    int $workedMinutes,
    int $candidateMinutes,
    int $extra25Minutes,
    int $extra50Minutes,
) {
    $quotaAnalysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: $entryAt,
        exitAt: $exitAt,
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));
    $candidate = $quotaAnalysis->overtimeCandidates->sole();

    expect($quotaAnalysis->workedMinutes)->toBe($workedMinutes)
        ->and($quotaAnalysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($candidate->start->toDateTimeString())->toBe($candidateStart)
        ->and($candidate->end->toDateTimeString())->toBe($exitAt)
        ->and($candidate->rateMinutes->ordinaryMinutes)->toBe(0)
        ->and($candidate->rateMinutes->extra25Minutes)->toBe($extra25Minutes)
        ->and($candidate->rateMinutes->extra50Minutes)->toBe($extra50Minutes)
        ->and($candidate->rateMinutes->totalMinutes())->toBe($candidateMinutes);
})->with([
    '06:00-16:00' => ['2026-07-20 06:00:00', '2026-07-20 16:00:00', '2026-07-20 14:00:00', 600, 120, 120, 0],
    '09:00-19:00' => ['2026-07-20 09:00:00', '2026-07-20 19:00:00', '2026-07-20 17:00:00', 600, 120, 60, 60],
    '12:00-21:00' => ['2026-07-20 12:00:00', '2026-07-20 21:00:00', '2026-07-20 20:00:00', 540, 60, 0, 60],
]);

test('keeps 00:00-09:00 at 480 ordinary plus 60 wall-clock extra25 minutes', function () {
    $preShiftAnalysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 00:00:00',
        exitAt: '2026-07-20 09:00:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));
    $candidate = $preShiftAnalysis->overtimeCandidates->sole();

    expect($preShiftAnalysis->workedMinutes)->toBe(540)
        ->and($preShiftAnalysis->scheduledMinutes)->toBe(480)
        ->and($preShiftAnalysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($candidate->start->toDateTimeString())->toBe('2026-07-20 08:00:00')
        ->and($candidate->end->toDateTimeString())->toBe('2026-07-20 09:00:00')
        ->and($candidate->rateMinutes->ordinaryMinutes)->toBe(0)
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(60)
        ->and($candidate->rateMinutes->extra75Minutes)->toBe(0)
        ->and($candidate->minutes)->toBe(60);
});

test('duration-first entry at 06:20 is within variation tolerance', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 06:20:00', '2026-07-20 14:20:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));

    expect($analysis->scheduledMinutes)->toBe(480)
        ->and($analysis->variations)->toBeEmpty();
});

test('duration-first 07:00-15:00 exposes a pay-neutral entry variation', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 07:00:00', '2026-07-20 15:00:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));
    $variation = $analysis->variations->sole();

    expect($analysis->scheduledRates->ordinaryMinutes)->toBe(480)
        ->and($analysis->overtimeCandidates)->toBeEmpty()
        ->and($variation->kind)->toBe('schedule_entry')
        ->and($variation->entryAt->toDateTimeString())->toBe('2026-07-20 07:00:00')
        ->and($variation->fingerprint)->toHaveLength(64);
});

test('duration-first incomplete quota emits no entry variation', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 07:00:00', '2026-07-20 14:00:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));

    expect($analysis->scheduledMinutes)->toBe(420)
        ->and($analysis->variations)->toBeEmpty();
});

test('duration-first completed overtime hour has zero transfer residual', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 06:00:00', '2026-07-20 15:00:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));

    expect($analysis->overtimeCandidates->sole()->minutes)->toBe(60)
        ->and($analysis->excludedTransferMinutes)->toBe(0);
});

test('duration-first excludes an exact one-minute transfer residual', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 06:00:00', '2026-07-20 15:01:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));

    expect($analysis->overtimeCandidates->sole()->minutes)->toBe(60)
        ->and($analysis->excludedTransferMinutes)->toBe(1)
        ->and($analysis->exitAt?->toDateTimeString())->toBe('2026-07-20 15:01:00');
});

test('duration-first aligns transfer exclusion to a Saturday overnight work date', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-18', '2026-07-18 18:00:00', '2026-07-19 03:20:00', '18:00', '02:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->workDate->toDateString())->toBe('2026-07-18')
        ->and($analysis->exitAt?->toDateTimeString())->toBe('2026-07-19 03:20:00')
        ->and($analysis->excludedTransferMinutes)->toBe(20)
        ->and($candidate->start->toDateTimeString())->toBe('2026-07-19 02:00:00')
        ->and($candidate->end->toDateTimeString())->toBe('2026-07-19 03:00:00')
        ->and($candidate->rateMinutes->extra75Minutes)->toBe(60);
});

test('duration-first excludes a 25-minute transfer residual exactly', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 06:00:00', '2026-07-20 16:25:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->workedMinutes)->toBe(625)
        ->and($analysis->excludedTransferMinutes)->toBe(25)
        ->and($candidate->minutes)->toBe(120)
        ->and($candidate->end->toDateTimeString())->toBe('2026-07-20 16:00:00')
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(120);
});

test('duration-first preserves every post-quota minute above the transfer threshold', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 06:00:00', '2026-07-20 16:31:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));
    $candidate = $analysis->overtimeCandidates->sole();

    expect($analysis->excludedTransferMinutes)->toBe(0)
        ->and($candidate->minutes)->toBe(151)
        ->and($candidate->end->toDateTimeString())->toBe('2026-07-20 16:31:00')
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(151);
});

test('duration-first excludes a 30-minute transfer residual at the threshold', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 06:00:00', '2026-07-20 15:30:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));

    expect($analysis->excludedTransferMinutes)->toBe(30)
        ->and($analysis->overtimeCandidates->sole()->minutes)->toBe(60);
});

test('duration-first keeps residual when no overtime hour is complete', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        '2026-07-20', '2026-07-20 06:00:00', '2026-07-20 14:25:00',
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ));

    expect($analysis->excludedTransferMinutes)->toBe(0)
        ->and($analysis->overtimeCandidates->sole()->minutes)->toBe(25);
});

test('overrides the entire duration-first interval on a Sunday or holiday', function (
    string $workDate,
    bool $isHoliday,
) {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: $workDate,
        entryAt: "{$workDate} 06:00:00",
        exitAt: "{$workDate} 16:00:00",
        payrollPolicyKey: WorkScheduleProfilePublication::DURATION_FIRST_V2,
    ), isHoliday: $isHoliday);

    expect($analysis->workedMinutes)->toBe(600)
        ->and($analysis->scheduledMinutes)->toBe(600)
        ->and($analysis->scheduledRates->ordinaryMinutes)->toBe(0)
        ->and($analysis->scheduledRates->extra25Minutes)->toBe(0)
        ->and($analysis->scheduledRates->extra50Minutes)->toBe(0)
        ->and($analysis->scheduledRates->extra75Minutes)->toBe(0)
        ->and($analysis->scheduledRates->extra100Minutes)->toBe(600)
        ->and($analysis->overtimeCandidates)->toBeEmpty();
})->with([
    'Sunday work date' => ['2026-07-19', false],
    'configured holiday work date' => ['2026-07-20', true],
]);

test('rejects a resolved occurrence with an unsupported immutable payroll policy key', function () {
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(attendanceOccurrence(
        workDate: '2026-07-20',
        entryAt: '2026-07-20 06:00:00',
        exitAt: '2026-07-20 14:00:00',
        payrollPolicyKey: 'duration-first-v3',
    ));

    expect($analysis->status)->toBe(AttendanceShiftAnalysis::UNSUPPORTED_PAYROLL_POLICY)
        ->and($analysis->workedMinutes)->toBe(0)
        ->and($analysis->scheduledMinutes)->toBe(0)
        ->and($analysis->scheduledRates->totalMinutes())->toBe(0)
        ->and($analysis->deficits)->toBeEmpty()
        ->and($analysis->overtimeCandidates)->toBeEmpty()
        ->and($analysis->payrollPolicyKey)->toBe('duration-first-v3');
});

function attendanceOccurrence(
    string $workDate,
    string $entryAt,
    string $exitAt,
    ?string $scheduledStart = '06:00',
    ?string $scheduledEnd = '14:00',
    ?array $bands = null,
    int $factGeneration = 0,
    string $payrollPolicyKey = WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1,
): ShiftOccurrence {
    $date = CarbonImmutable::parse($workDate)->startOfDay();
    $schedule = (new WorkSchedule)->forceFill([
        'id' => 10,
        'work_schedule_profile_id' => 20,
        'is_working_day' => $scheduledStart !== null,
        'start_time' => $scheduledStart,
        'end_time' => $scheduledEnd,
        'banding_json' => $bands,
    ]);
    $assignment = (new EmployeeScheduleAssignment)->forceFill([
        'id' => 30,
        'work_schedule_profile_id' => 20,
    ]);
    $entry = (new RawMark)->forceFill([
        'id' => 40,
        'event_at' => $entryAt,
        'status' => 'valid',
        'metadata' => ['revisions' => [['revision' => 1]]],
    ]);
    $exit = (new RawMark)->forceFill([
        'id' => 41,
        'event_at' => $exitAt,
        'status' => 'valid',
        'metadata' => ['revisions' => [['revision' => 2]]],
    ]);
    $start = $scheduledStart === null ? null : $date->setTimeFromTimeString($scheduledStart);
    $end = $scheduledEnd === null ? null : $date->setTimeFromTimeString($scheduledEnd);

    if ($start !== null && $end?->lte($start)) {
        $end = $end->addDay();
    }

    return new ShiftOccurrence(
        workDate: $date,
        assignment: $assignment,
        schedule: $schedule,
        scheduledStart: $start,
        scheduledEnd: $end,
        marks: collect([$entry, $exit]),
        status: ShiftOccurrence::RESOLVED,
        factGeneration: $factGeneration,
        payrollPolicyKey: $payrollPolicyKey,
    );
}
