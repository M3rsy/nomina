<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Services\Payroll\PayrollResultsReviewProjection;

test('summarizes absence days using PostgreSQL boolean values', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'current_result_generation' => 1,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();

    PayrollResult::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create([
        'date' => '2026-01-05',
        'is_absence' => true,
    ]);
    PayrollResult::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create([
        'date' => '2026-01-06',
        'is_absence' => false,
    ]);

    expect(app(PayrollResultsReviewProjection::class)->summary($period, null, null))
        ->toMatchArray([
            'total_days' => 2,
            'total_employees' => 1,
            'absence_days' => 1,
        ]);
});
