<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\AttendanceShiftAnalysis;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\PayrollPeriodSnapshotData;
use App\Services\Attendance\PayrollShiftEvaluation;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
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

function payrollPolicyFixture(array $marks = [], array $profileAttributes = []): array
{
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create($profileAttributes);
    WorkSchedule::factory()->forProfile($profile)->create(['day_of_week' => 1]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada heredada');
    $period = PayPeriod::factory()->forCompany($company)->create(['start_date' => '2026-07-20', 'end_date' => '2026-07-20']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    foreach ($marks as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']);
    }

    return compact('company', 'profile', 'employee', 'period');
}

test('exposes the immutable legacy payroll policy publication for a work date', function () {
    ['employee' => $employee] = payrollPolicyFixture();

    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    expect($occurrence->payrollPolicyKey)->toBe('schedule-overlap-v1')
        ->and($occurrence->publicationId)->toBeInt()
        ->and($occurrence->publicationId)->toBeGreaterThan(0)
        ->and($occurrence->status)->toBe(ShiftOccurrence::NO_MARKS);
});

test('fails closed unless exactly one payroll policy publication applies', function () {
    ['profile' => $profile, 'employee' => $employee] = payrollPolicyFixture();
    $original = WorkScheduleProfilePublication::withoutCompanyScope()->where('profile_id', $profile->id)->sole();
    $original->delete();
    $missing = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    $original->replicate()->save();
    $original->replicate()->forceFill(['request_key' => str_repeat('a', 64), 'payload_hash' => str_repeat('b', 64)])->save();
    $ambiguous = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    expect($missing->status)->toBe(ShiftOccurrence::MISSING_PUBLICATION)
        ->and($ambiguous->status)->toBe(ShiftOccurrence::AMBIGUOUS_PUBLICATION)
        ->and($ambiguous->publicationId)->toBeNull()
        ->and($ambiguous->payrollPolicyKey)->toBeNull();
});

test('does not bind an overnight mark to a work date without one publication', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1, 'start_time' => '18:00', 'end_time' => '06:00', 'base_ordinary_hours' => 12,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada nocturna');
    WorkScheduleProfilePublication::withoutCompanyScope()->where('profile_id', $profile->id)->delete();

    $workDate = app(ShiftOccurrenceResolver::class)->workDateFor($employee, '2026-07-21 02:00:00');

    expect($workDate->toDateString())->toBe('2026-07-21');
});

test('fails closed when live or captured assignment coverage is ambiguous', function () {
    ['company' => $company, 'profile' => $firstProfile, 'employee' => $employee, 'period' => $period] = payrollPolicyFixture();
    $secondProfile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($secondProfile)->create(['day_of_week' => 1]);
    EmployeeScheduleAssignment::withoutCompanyScope()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_profile_id' => $secondProfile->id,
        'effective_from' => '2026-07-10',
        'effective_to' => null,
        'reason' => 'Cobertura superpuesta inválida',
    ]);
    $snapshot = PayrollPeriodSnapshotData::capture(
        $period,
        Employee::withoutCompanyScope()->whereKey($employee->id)->get(),
    );
    $live = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    $captured = app(ShiftOccurrenceResolver::class)
        ->resolveFromSnapshot($employee, '2026-07-20', $snapshot);

    expect($live->status)->toBe(ShiftOccurrence::AMBIGUOUS_ASSIGNMENT)
        ->and($captured->status)->toBe(ShiftOccurrence::AMBIGUOUS_ASSIGNMENT)
        ->and($captured->assignment)->toBeNull();
});

test('resolves payroll policy identity from the captured payroll snapshot', function () {
    ['profile' => $profile, 'employee' => $employee, 'period' => $period] = payrollPolicyFixture();
    $snapshot = PayrollPeriodSnapshotData::capture(
        $period,
        Employee::withoutCompanyScope()->whereKey($employee->id)->get(),
    );
    $publicationId = WorkScheduleProfilePublication::withoutCompanyScope()
        ->where('profile_id', $profile->id)
        ->sole()
        ->id;
    WorkScheduleProfilePublication::withoutCompanyScope()->whereKey($publicationId)->delete();

    $occurrence = app(ShiftOccurrenceResolver::class)
        ->resolveFromSnapshot($employee, '2026-07-20', $snapshot);

    expect($occurrence->publicationId)->toBe($publicationId)
        ->and($occurrence->payrollPolicyKey)->toBe('schedule-overlap-v1')
        ->and($occurrence->status)->toBe(ShiftOccurrence::NO_MARKS);
});

test('propagates publication identity into analysis and attendance fact fingerprints', function () {
    ['employee' => $employee] = payrollPolicyFixture(['2026-07-20 05:30:00', '2026-07-20 14:00:00']);
    $resolver = app(ShiftOccurrenceResolver::class);
    $analyzer = app(AttendanceShiftAnalyzer::class);
    $firstOccurrence = $resolver->resolve($employee, '2026-07-20');
    $firstAnalysis = $analyzer->analyze($firstOccurrence);
    $firstFingerprint = $firstAnalysis->overtimeCandidates->sole()->fingerprint;
    $original = WorkScheduleProfilePublication::withoutCompanyScope()->findOrFail($firstOccurrence->publicationId);
    $replacement = $original->replicate();
    $replacement->request_key = str_repeat('c', 64);
    $replacement->payload_hash = str_repeat('d', 64);
    $original->delete();
    $replacement->save();

    $secondOccurrence = $resolver->resolve($employee, '2026-07-20');
    $secondAnalysis = $analyzer->analyze($secondOccurrence);

    expect($firstAnalysis->publicationId)->toBe($firstOccurrence->publicationId)
        ->and($firstAnalysis->payrollPolicyKey)->toBe('schedule-overlap-v1')
        ->and($secondAnalysis->overtimeCandidates->sole()->fingerprint)->not->toBe($firstFingerprint)
        ->and($secondOccurrence->publicationId)->toBe($replacement->id);
});

test('propagates publication provenance through payroll evaluation output', function () {
    ['profile' => $profile, 'employee' => $employee, 'period' => $period] = payrollPolicyFixture(['2026-07-20 06:00:00', '2026-07-20 14:00:00']);
    $publicationId = WorkScheduleProfilePublication::withoutCompanyScope()
        ->where('profile_id', $profile->id)
        ->sole()
        ->id;

    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($period, $employee, '2026-07-20');

    expect($evaluation->status)->toBe(PayrollShiftEvaluation::PROCESSABLE)
        ->and($evaluation->publicationId)->toBe($publicationId)
        ->and($evaluation->payrollPolicyKey)->toBe('schedule-overlap-v1')
        ->and($evaluation->metadata['work_schedule_profile_publication_id'])->toBe($publicationId)
        ->and($evaluation->metadata['payroll_policy_key'])->toBe('schedule-overlap-v1');
});

test('does not run legacy analysis for an explicitly published duration first policy', function () {
    ['company' => $company, 'profile' => $profile, 'employee' => $employee] = payrollPolicyFixture([], ['profile_key' => 'general']);
    $actor = User::factory()->forCompany($company)->create();
    $publication = WorkScheduleProfilePublication::withoutCompanyScope()->where('profile_id', $profile->id)->sole();
    $publication->delete();
    $publication->forceFill([
        'id' => null,
        'payroll_policy_key' => 'duration-first-v2',
        'request_key' => str_repeat('e', 64),
        'payload_hash' => str_repeat('f', 64),
        'published_by' => $actor->id,
    ])->save();

    $analysis = app(AttendanceShiftAnalyzer::class)->analyze(
        app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20'),
    );

    expect($analysis->status)->toBe(AttendanceShiftAnalysis::UNSUPPORTED_PAYROLL_POLICY)
        ->and($analysis->deficits)->toHaveCount(0)
        ->and($analysis->overtimeCandidates)->toHaveCount(0)
        ->and($analysis->payrollPolicyKey)->toBe('duration-first-v2');
});
