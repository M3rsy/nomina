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
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Attendance\ShiftOccurrenceResolver;

test('resolves an overnight shift across pay periods by its starting work date', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Turno nocturno');

    $firstPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-20',
    ]);
    $secondPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-21',
        'end_date' => '2026-07-31',
    ]);
    $firstFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($firstPeriod)->create();
    $secondFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($secondPeriod)->create();
    $entry = RawMark::factory()->forCompany($company)->forPayPeriod($firstPeriod)
        ->forUploadedFile($firstFile)->forEmployee($employee)->create([
            'event_at' => '2026-07-20 18:00:00',
            'status' => 'valid',
        ]);
    $exit = RawMark::factory()->forCompany($company)->forPayPeriod($secondPeriod)
        ->forUploadedFile($secondFile)->forEmployee($employee)->create([
            'event_at' => '2026-07-21 06:00:00',
            'status' => 'valid',
        ]);
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    expect($occurrence->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($occurrence->scheduledStart?->toDateTimeString())->toBe('2026-07-20 18:00:00')
        ->and($occurrence->scheduledEnd?->toDateTimeString())->toBe('2026-07-21 06:00:00')
        ->and($occurrence->entryMark()?->is($entry))->toBeTrue()
        ->and($occurrence->exitMark()?->is($exit))->toBeTrue();
});

test('keeps adjacent overnight shift marks in their own work dates', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();

    foreach ([1, 2] as $dayOfWeek) {
        WorkSchedule::factory()->forProfile($profile)->create([
            'day_of_week' => $dayOfWeek,
            'start_time' => '18:00',
            'end_time' => '06:00',
            'base_ordinary_hours' => 12,
        ]);
    }
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Turno nocturno');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-22',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    foreach (['2026-07-20 18:00:00', '2026-07-21 06:00:00', '2026-07-21 18:00:00', '2026-07-22 06:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }
    $monday = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    $tuesday = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-21');
    expect($monday->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($monday->entryMark()?->event_at->toDateTimeString())->toBe('2026-07-20 18:00:00')
        ->and($monday->exitMark()?->event_at->toDateTimeString())->toBe('2026-07-21 06:00:00')
        ->and($tuesday->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($tuesday->entryMark()?->event_at->toDateTimeString())->toBe('2026-07-21 18:00:00')
        ->and($tuesday->exitMark()?->event_at->toDateTimeString())->toBe('2026-07-22 06:00:00');
});

test('keeps a late overnight exit on its starting work date before a non-working day', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 2,
        'is_working_day' => false,
        'start_time' => null,
        'end_time' => null,
        'base_ordinary_hours' => 0,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Turno nocturno');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-21',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    foreach (['2026-07-20 18:00:00', '2026-07-21 06:30:00', '2026-07-21 10:00:00', '2026-07-21 12:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    $resolver = app(ShiftOccurrenceResolver::class);
    $overnight = $resolver->resolve($employee, '2026-07-20');
    $nonWorkingDay = $resolver->resolve($employee, '2026-07-21');
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze($overnight);

    expect($overnight->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($overnight->exitMark()?->event_at->toDateTimeString())->toBe('2026-07-21 06:30:00')
        ->and($analysis->overtimeCandidates)->toHaveCount(1)
        ->and($analysis->overtimeCandidates->first()->kind)->toBe('post_shift')
        ->and($analysis->overtimeCandidates->first()->minutes)->toBe(30)
        ->and($nonWorkingDay->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($nonWorkingDay->entryMark()?->event_at->toDateTimeString())->toBe('2026-07-21 10:00:00')
        ->and($resolver->workDateFor($employee, '2026-07-21 06:30:00')->toDateString())->toBe('2026-07-20')
        ->and($resolver->workDateFor($employee, '2026-07-21 10:00:00')->toDateString())->toBe('2026-07-21');
});

test('bridges one very late overnight exit across a non-working day boundary', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 2,
        'is_working_day' => false,
        'start_time' => null,
        'end_time' => null,
        'base_ordinary_hours' => 0,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Turno nocturno');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-21',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    foreach (['2026-07-20 18:00:00', '2026-07-21 10:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    $resolver = app(ShiftOccurrenceResolver::class);
    $overnight = $resolver->resolve($employee, '2026-07-20');
    $nonWorkingDay = $resolver->resolve($employee, '2026-07-21');
    $analysis = app(AttendanceShiftAnalyzer::class)->analyze($overnight);
    $candidateKey = $analysis->overtimeCandidates->first()?->key;

    expect($overnight->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($overnight->exitMark()?->event_at->toDateTimeString())->toBe('2026-07-21 10:00:00')
        ->and($analysis->overtimeCandidates->first()?->kind)->toBe('post_shift')
        ->and($analysis->overtimeCandidates->first()?->minutes)->toBe(240)
        ->and($nonWorkingDay->status)->toBe(ShiftOccurrence::NO_MARKS)
        ->and($resolver->workDateFor($employee, '2026-07-21 10:00:00')->toDateString())->toBe('2026-07-20');

    app(AttendanceFactGenerationTracker::class)->advance($employee, '2026-07-21');
    $newCandidateKey = app(AttendanceShiftAnalyzer::class)
        ->analyze($resolver->resolve($employee, '2026-07-20'))
        ->overtimeCandidates
        ->first()?->key;

    expect($newCandidateKey)->not->toBe($candidateKey);
});

test('reports incomplete and ambiguous mark pairs instead of guessing', function () {
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
    $makeMark = function (string $eventAt) use ($company, $employee, $file, $period): void {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    };
    $makeMark('2026-07-20 06:00:00');
    $single = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    $makeMark('2026-07-20 14:00:00');
    $paired = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    RawMark::factory()->forCompany($company)->forPayPeriod($period)
        ->forUploadedFile($file)->forEmployee($employee)->create([
            'event_at' => '2026-07-20 12:00:00',
            'status' => 'deleted',
        ]);
    $withDeletedMark = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    $makeMark('2026-07-20 14:30:00');
    $ambiguous = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    expect($single->status)->toBe(ShiftOccurrence::MISSING_PAIR)
        ->and($single->entryMark())->toBeNull()
        ->and($paired->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($withDeletedMark->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($ambiguous->status)->toBe(ShiftOccurrence::AMBIGUOUS)
        ->and($ambiguous->entryMark())->toBeNull();
});

test('resolves the schedule profile effective on each work date', function () {
    $company = Company::factory()->create();
    $dayProfile = WorkScheduleProfile::factory()->forCompany($company)->create(['name' => 'Diurna']);
    WorkSchedule::factory()->forProfile($dayProfile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
    ]);
    $nightProfile = WorkScheduleProfile::factory()->forCompany($company)->create(['name' => 'Nocturna']);
    WorkSchedule::factory()->forProfile($nightProfile)->create([
        'day_of_week' => 2,
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);
    $assigner->assign($employee, $dayProfile, '2026-07-01', 'Turno inicial');
    $assigner->assign($employee, $nightProfile, '2026-07-21', 'Rotación nocturna');
    $monday = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    $tuesday = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-21');
    expect($monday->scheduledStart?->toDateTimeString())->toBe('2026-07-20 06:00:00')
        ->and($monday->scheduledEnd?->toDateTimeString())->toBe('2026-07-20 14:00:00')
        ->and($tuesday->scheduledStart?->toDateTimeString())->toBe('2026-07-21 18:00:00')
        ->and($tuesday->scheduledEnd?->toDateTimeString())->toBe('2026-07-22 06:00:00');
});

test('pairs marks on an assigned non-working date without inventing schedule boundaries', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 0,
        'is_working_day' => false,
        'start_time' => null,
        'end_time' => null,
        'base_ordinary_hours' => 0,
    ]);
    WorkSchedule::factory()->forProfile($profile)->create(['day_of_week' => 1]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada general');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-19',
        'end_date' => '2026-07-20',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    foreach (['2026-07-19 10:00:00', '2026-07-19 22:30:00', '2026-07-20 06:00:00', '2026-07-20 14:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-19');
    $monday = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    expect($occurrence->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($occurrence->scheduledStart)->toBeNull()
        ->and($occurrence->scheduledEnd)->toBeNull()
        ->and($occurrence->entryMark()?->event_at->toDateTimeString())->toBe('2026-07-19 10:00:00')
        ->and($occurrence->exitMark()?->event_at->toDateTimeString())->toBe('2026-07-19 22:30:00')
        ->and($monday->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($monday->entryMark()?->event_at->toDateTimeString())->toBe('2026-07-20 06:00:00');
});

test('reports missing assignment and missing weekday schedule separately', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $withoutAssignment = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada incompleta');
    $withoutSchedule = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    expect($withoutAssignment->status)->toBe(ShiftOccurrence::MISSING_ASSIGNMENT)
        ->and($withoutSchedule->status)->toBe(ShiftOccurrence::MISSING_SCHEDULE);
});
