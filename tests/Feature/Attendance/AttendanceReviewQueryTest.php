<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceReviewQuery;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('projects exact overtime candidates from the shared shift analysis', function () {
    $context = attendanceReviewFixture();

    $review = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole();
    $candidate = $review->analysis->overtimeCandidates->sole();

    expect($review->employee->is($context['employee']))->toBeTrue()
        ->and($review->occurrence->scheduledStart?->toDateTimeString())->toBe('2026-07-20 06:00:00')
        ->and($review->occurrence->scheduledEnd?->toDateTimeString())->toBe('2026-07-20 14:00:00')
        ->and($review->analysis->entryAt?->toDateTimeString())->toBe('2026-07-20 06:00:00')
        ->and($review->analysis->exitAt?->toDateTimeString())->toBe('2026-07-20 14:30:00')
        ->and($review->analysis->scheduledMinutes)->toBe(480)
        ->and($candidate->kind)->toBe('post_shift')
        ->and($candidate->minutes)->toBe(30)
        ->and($candidate->rateMinutes->extra25Minutes)->toBe(30)
        ->and($review->decisionFor($candidate))->toBeNull();
});

test('projects exact attendance deficits even when there is no overtime candidate', function () {
    $context = attendanceReviewFixture();
    RawMark::withoutCompanyScope()
        ->where('uploaded_file_id', $context['entry_file']->id)
        ->update(['event_at' => '2026-07-20 06:15:00']);
    RawMark::withoutCompanyScope()
        ->where('uploaded_file_id', $context['exit_file']->id)
        ->update(['event_at' => '2026-07-20 14:00:00']);

    $review = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole();
    $deficit = $review->analysis->deficits->sole();

    expect($review->analysis->overtimeCandidates)->toBeEmpty()
        ->and($deficit->kind)->toBe('late_arrival')
        ->and($deficit->minutes)->toBe(15)
        ->and($deficit->rateMinutes->ordinaryMinutes)->toBe(15)
        ->and($review->exceptionFor($deficit))->toBeNull();
});

test('file filtering changes visibility without removing marks from shift analysis', function () {
    $context = attendanceReviewFixture();
    $unrelatedFile = UploadedFile::factory()
        ->forCompany($context['company'])
        ->forPayPeriod($context['period'])
        ->create();
    $query = app(AttendanceReviewQuery::class);

    $fromEntryFile = $query->forPeriod($context['period'], $context['entry_file']->id)->sole();
    $fromExitFile = $query->forPeriod($context['period'], $context['exit_file']->id)->sole();

    expect($fromEntryFile->occurrence->marks)->toHaveCount(2)
        ->and($fromEntryFile->analysis->overtimeCandidates->sole()->minutes)->toBe(30)
        ->and($fromExitFile->occurrence->marks)->toHaveCount(2)
        ->and($query->forPeriod($context['period'], $unrelatedFile->id))->toBeEmpty();
});

test('exposes the current append-only decision for its exact candidate', function () {
    $context = attendanceReviewFixture();
    $query = app(AttendanceReviewQuery::class);
    $candidate = $query->forPeriod($context['period'])->sole()->analysis->overtimeCandidates->sole();

    $decision = app(OvertimeDecisionRecorder::class)->decide(
        $context['period'],
        $context['employee'],
        '2026-07-20',
        $candidate->key,
        OvertimeDecision::REJECTED,
        'Traslado desde el puesto hasta el reloj',
        $context['actor'],
    );
    $review = $query->forPeriod($context['period'])->sole();

    expect($review->decisionFor($review->analysis->overtimeCandidates->sole())?->is($decision))->toBeTrue()
        ->and($review->currentDecisions)->toHaveCount(1)
        ->and($review->currentDecisions->sole()->relationLoaded('decider'))->toBeTrue();
});

function attendanceReviewFixture(): array
{
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'base_ordinary_hours' => 8,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada diurna');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-20',
        'status' => 'uploaded',
    ]);
    $entryFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $exitFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    RawMark::factory()->forCompany($company)->forPayPeriod($period)
        ->forUploadedFile($entryFile)->forEmployee($employee)->create([
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'valid',
        ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($period)
        ->forUploadedFile($exitFile)->forEmployee($employee)->create([
            'event_at' => '2026-07-20 14:30:00',
            'status' => 'valid',
        ]);

    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);

    return compact('company', 'employee', 'period', 'actor') + [
        'entry_file' => $entryFile,
        'exit_file' => $exitFile,
    ];
}
