<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\PayrollPeriodReviewSnapshot;
use App\Services\CurrentCompany;

test('period review excludes employee dates before their hire date', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create([
        'hired_at' => '2026-01-07',
    ]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();

    foreach (range(0, 6) as $day) {
        WorkSchedule::factory()->forProfile($profile)->create([
            'day_of_week' => $day,
            'start_time' => '06:00',
            'end_time' => '14:00',
            'base_ordinary_hours' => 8,
        ]);
    }

    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-01-01', 'Initial schedule');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-08',
        'status' => 'uploaded',
    ]);
    app(CurrentCompany::class)->set($company);

    $snapshot = app(PayrollPeriodReviewSnapshot::class)->forPeriod($period);

    expect($snapshot['reviews']->map(
        fn ($review): string => $review->occurrence->workDate->toDateString(),
    )->all())->toBe(['2026-01-07', '2026-01-08']);
});

test('employee scoped review evaluates only the supplied period employees', function () {
    $company = Company::factory()->create();
    $included = Employee::factory()->forCompany($company)->create(['hired_at' => null]);
    Employee::factory()->forCompany($company)->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'uploaded',
    ]);
    app(CurrentCompany::class)->set($company);

    $snapshot = app(PayrollPeriodReviewSnapshot::class)->forEmployees($period, collect([$included]));

    expect($snapshot['reviews'])->toHaveCount(1)
        ->and($snapshot['reviews']->sole()->employee->is($included))->toBeTrue();
});

test('period review can skip blockers for read only screens', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create(['hired_at' => null]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'base_ordinary_hours' => 8,
    ]);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-01-05', 'Initial schedule');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'uploaded',
    ]);
    app(CurrentCompany::class)->set($company);

    $snapshot = app(PayrollPeriodReviewSnapshot::class)->forPeriod($period, includeBlockers: false);

    expect($snapshot['reviews'])->toHaveCount(1)
        ->and($snapshot['absences'])->toHaveCount(1)
        ->and($snapshot['blockers'])->toBeEmpty();
});

test('employee scoped review rejects employees from another company', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'uploaded',
    ]);
    $outsideEmployee = Employee::factory()->forCompany(Company::factory()->create())->create();

    expect(fn () => app(PayrollPeriodReviewSnapshot::class)->forEmployees(
        $period,
        collect([$outsideEmployee]),
    ))->toThrow(InvalidArgumentException::class, 'Employees must belong to the payroll period company.');
});
