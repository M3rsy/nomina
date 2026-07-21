<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

test('payroll process command processes a ready pay period', function () {
    $this->seed(PermissionRoleSeeder::class);

    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'ready',
    ]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();

    foreach ($company->defaultWorkSchedules() as $day => $schedule) {
        WorkSchedule::factory()->forProfile($profile)->create($schedule + ['day_of_week' => $day]);
    }

    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2020-01-01', 'Jornada para nómina');

    app(CurrentCompany::class)->set($company);

    $this->artisan('payroll:process', ['payPeriodId' => (string) $payPeriod->id])
        ->assertSuccessful()
        ->expectsOutput('Payroll processed successfully.');

    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(1);
});
