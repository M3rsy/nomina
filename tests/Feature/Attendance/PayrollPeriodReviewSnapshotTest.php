<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
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

test('period review preserves canonical rates for custom bands, repeats, and company isolation', function () {
    $company = Company::factory()->create();
    $outsideCompany = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-20',
        'status' => 'uploaded',
    ]);
    $canonicalProfile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $customProfile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $outsideProfile = WorkScheduleProfile::factory()->forCompany($outsideCompany)->create();

    foreach ([
        [$canonicalProfile, null],
        [$customProfile, [['start' => '00:00', 'end' => '24:00', 'rate' => 100]]],
        [$outsideProfile, [['start' => '00:00', 'end' => '24:00', 'rate' => 100]]],
    ] as [$profile, $bands]) {
        WorkSchedule::factory()->forProfile($profile)->create([
            'day_of_week' => 1,
            'start_time' => '06:00',
            'end_time' => '14:00',
            'base_ordinary_hours' => 8,
            'banding_json' => $bands,
        ]);
    }

    $canonicalEmployee = Employee::factory()->forCompany($company)->create();
    $customEmployee = Employee::factory()->forCompany($company)->create();
    $outsideEmployee = Employee::factory()->forCompany($outsideCompany)->create();

    app(EmployeeScheduleAssigner::class)->assign($canonicalEmployee, $canonicalProfile, '2026-07-01', 'Canonical schedule');
    app(EmployeeScheduleAssigner::class)->assign($customEmployee, $customProfile, '2026-07-01', 'Historical custom schedule');
    app(EmployeeScheduleAssigner::class)->assign($outsideEmployee, $outsideProfile, '2026-07-01', 'Outside schedule');

    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    foreach ([$canonicalEmployee, $customEmployee] as $employee) {
        foreach (['2026-07-20 06:00:00', '2026-07-20 14:30:00'] as $eventAt) {
            RawMark::factory()->forCompany($company)->forPayPeriod($period)
                ->forUploadedFile($file)->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']);
        }
    }

    app(CurrentCompany::class)->set($company);
    $first = app(PayrollPeriodReviewSnapshot::class)->forPeriod($period, includeBlockers: false)['reviews'];
    $second = app(PayrollPeriodReviewSnapshot::class)->forPeriod($period, includeBlockers: false)['reviews'];
    $rates = fn ($review): array => (array) $review->analysis->scheduledRates->plus(
        $review->analysis->overtimeCandidates->sole()->rateMinutes,
    );
    $firstRates = $first->map($rates)->sort()->values();
    $secondRates = $second->map($rates)->sort()->values();

    expect($first->map(fn ($review): int => $review->employee->id)->sort()->values()->all())->toBe([$canonicalEmployee->id, $customEmployee->id])
        ->and($firstRates)->toEqual($secondRates)
        ->and($firstRates)->toEqual(collect([
            ['ordinaryMinutes' => 480, 'extra25Minutes' => 30, 'extra50Minutes' => 0, 'extra75Minutes' => 0, 'extra100Minutes' => 0],
            ['ordinaryMinutes' => 480, 'extra25Minutes' => 30, 'extra50Minutes' => 0, 'extra75Minutes' => 0, 'extra100Minutes' => 0],
        ]));
});

test('captured review context streams the same employee days and derived evidence as materialization', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-21',
        'status' => 'uploaded',
    ]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    foreach ([1, 2] as $dayOfWeek) {
        WorkSchedule::factory()->forProfile($profile)->create([
            'day_of_week' => $dayOfWeek,
            'start_time' => '06:00',
            'end_time' => '14:00',
            'base_ordinary_hours' => 8,
        ]);
    }
    $employee = Employee::factory()->forCompany($company)->create(['hired_at' => '2026-07-20']);
    $outside = Employee::factory()->forCompany(Company::factory()->create())->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Streaming parity');
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    foreach (['2026-07-20 06:15:00', '2026-07-20 14:30:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->forEmployee($employee)
            ->create(['event_at' => $eventAt, 'status' => 'valid']);
    }
    app(CurrentCompany::class)->set($company);

    $snapshots = app(PayrollPeriodReviewSnapshot::class);
    $materialized = $snapshots->forPeriod($period);
    $context = $snapshots->captureForPeriod($period);
    $streamed = collect();
    $snapshots->forEachReview($context, fn ($review) => $streamed->push($review));

    $signature = fn ($review): array => [
        $review->employee->id,
        $review->occurrence->workDate->toDateString(),
        $review->analysis->deficits->map(fn ($segment): string => $segment->key)->all(),
        $review->analysis->overtimeCandidates->map(fn ($segment): string => $segment->key)->all(),
        $review->analysis->variations->map(fn ($variation): string => $variation->key)->all(),
    ];

    expect($streamed->map($signature)->all())->toEqual($materialized['reviews']->map($signature)->all())
        ->and($streamed->pluck('employee.id')->all())->not->toContain($outside->id)
        ->and($snapshots->blockers($context)->all())->toEqual($materialized['blockers']->all())
        ->and($snapshots->absences($context)->map(fn (array $absence): array => [$absence['employee']->id, $absence['date']->toDateString()])->all())
        ->toEqual($materialized['absences']->map(fn (array $absence): array => [$absence['employee']->id, $absence['date']->toDateString()])->all());
});
