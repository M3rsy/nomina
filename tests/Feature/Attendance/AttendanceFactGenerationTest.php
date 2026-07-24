<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Attendance\ShiftOccurrenceResolver;
use Carbon\CarbonImmutable;

test('attendance fact generations advance monotonically per employee and work date', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->for($company)->create();
    $otherEmployee = Employee::factory()->for($company)->create();
    $tracker = app(AttendanceFactGenerationTracker::class);

    expect($tracker->current($employee, '2026-07-20'))->toBe(0)
        ->and($tracker->advance($employee, '2026-07-20'))->toBe(1)
        ->and($tracker->advance($employee, '2026-07-20'))->toBe(2)
        ->and($tracker->current($employee, '2026-07-20'))->toBe(2)
        ->and($tracker->current($employee, '2026-07-21'))->toBe(0)
        ->and($tracker->current($otherEmployee, '2026-07-20'))->toBe(0);
});

test('resolved occurrences expose their current fact generation in decision identity', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create(['day_of_week' => 1]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada diurna');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-20',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    foreach (['2026-07-20 06:00:00', '2026-07-20 14:30:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    $resolver = app(ShiftOccurrenceResolver::class);
    $analyzer = app(AttendanceShiftAnalyzer::class);
    $tracker = app(AttendanceFactGenerationTracker::class);
    $before = $resolver->resolve($employee, '2026-07-20');
    $beforeCandidate = $analyzer->analyze($before)->overtimeCandidates->sole();

    $tracker->advance($employee, '2026-07-20');

    $after = $resolver->resolve($employee, '2026-07-20');
    $afterCandidate = $analyzer->analyze($after)->overtimeCandidates->sole();

    expect($before->factGeneration)->toBe(0)
        ->and($after->factGeneration)->toBe(1)
        ->and($afterCandidate->key)->not->toBe($beforeCandidate->key);
});

test('calendar generation extends decision identity without changing generation zero fingerprints', function () {
    $assignment = new EmployeeScheduleAssignment;
    $assignment->id = 11;
    $schedule = new WorkSchedule;
    $schedule->id = 22;
    $schedule->forceFill([
        'start_time' => '06:00',
        'end_time' => '14:00',
        'is_working_day' => true,
        'banding_json' => null,
    ]);
    $entry = new RawMark;
    $entry->id = 33;
    $entry->forceFill(['event_at' => '2026-02-02 06:00:00', 'metadata' => null]);
    $exit = new RawMark;
    $exit->id = 44;
    $exit->forceFill(['event_at' => '2026-02-02 14:30:00', 'metadata' => null]);
    $date = CarbonImmutable::parse('2026-02-02');
    $occurrence = new ShiftOccurrence(
        $date,
        $assignment,
        $schedule,
        $date->setTime(6, 0),
        $date->setTime(14, 0),
        collect([$entry, $exit]),
        ShiftOccurrence::RESOLVED,
    );
    $analyzer = app(AttendanceShiftAnalyzer::class);
    $generationZero = $analyzer->analyze($occurrence, false, 0)->overtimeCandidates->sole();
    $generationOne = $analyzer->analyze($occurrence, false, 1)->overtimeCandidates->sole();

    expect($generationZero->fingerprint)
        ->toBe('307be61c771a40eb0f5a859a2372c7137b6e361b0a342364d0b9bbe7af4fc54f')
        ->and($generationOne->fingerprint)->not->toBe($generationZero->fingerprint);
});
