<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Services\CurrentCompany;

test('payroll process command processes a ready pay period', function () {
    $this->seed(\Database\Seeders\PermissionRoleSeeder::class);

    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'ready',
    ]);
    Employee::factory()->forCompany($company)->create();

    app(CurrentCompany::class)->set($company);

    $this->artisan('payroll:process', ['payPeriodId' => (string) $payPeriod->id])
        ->assertSuccessful()
        ->expectsOutput('Payroll processed successfully.');

    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(1);
});
