<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\ShiftOccurrenceResolver;

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
