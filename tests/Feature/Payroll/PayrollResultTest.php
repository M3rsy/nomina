<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('payroll result belongs to company, pay period and employee', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    $result = PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-15',
        'ordinary_hours' => 8.0,
    ]);

    expect($result->company_id)->toBe($company->id)
        ->and($result->payPeriod->id)->toBe($payPeriod->id)
        ->and($result->employee->id)->toBe($employee->id)
        ->and($result->date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($result->ordinary_hours)->toEqual(8.0)
        ->and($result->employee_external_id)->toBe($employee->external_id)
        ->and($result->employee_name)->toBe($employee->full_name);
});

test('payroll results are scoped by current company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    PayrollResult::factory()->forCompany($companyA)->create();
    PayrollResult::factory()->forCompany($companyB)->create();

    app(CurrentCompany::class)->set($companyA);

    expect(PayrollResult::count())->toBe(1);
    expect(PayrollResult::first()->company_id)->toBe($companyA->id);
});

test('without company scope bypasses the global scope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    PayrollResult::factory()->forCompany($companyA)->create();
    PayrollResult::factory()->forCompany($companyB)->create();

    app(CurrentCompany::class)->set($companyA);

    expect(PayrollResult::withoutCompanyScope()->count())->toBe(2);
});

test('duplicate payroll result for same employee and date violates unique constraint', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-20',
    ]);

    expect(fn () => PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-20',
    ]))->toThrow(QueryException::class);
});

test('casts are applied correctly', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    $result = PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-15',
        'entry_at' => '2026-01-15 08:00:00',
        'exit_at' => '2026-01-15 17:00:00',
        'worked_hours' => 9.0,
        'ordinary_hours' => 8.0,
        'extra_25_hours' => 1,
        'extra_50_hours' => 0,
        'extra_75_hours' => 0,
        'extra_100_hours' => 0,
        'is_absence' => true,
        'is_justified' => true,
        'unjustified' => false,
        'metadata' => ['audit' => 'test'],
    ]);

    expect($result->date)->toBeInstanceOf(Carbon::class)
        ->and($result->entry_at)->toBeInstanceOf(Carbon::class)
        ->and($result->exit_at)->toBeInstanceOf(Carbon::class)
        ->and($result->is_absence)->toBeTrue()
        ->and($result->is_justified)->toBeTrue()
        ->and($result->unjustified)->toBeFalse()
        ->and($result->metadata)->toBe(['audit' => 'test']);
});

test('persists exact payroll totals in canonical integer minutes', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    $result = PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'worked_hours' => 8.5,
        'ordinary_hours' => 8,
        'extra_25_hours' => 0.5,
        'worked_minutes' => 510,
        'scheduled_minutes' => 480,
        'recognized_minutes' => 510,
        'detected_overtime_minutes' => 30,
        'approved_overtime_minutes' => 30,
        'ordinary_minutes' => 480,
        'extra_25_minutes' => 30,
        'extra_50_minutes' => 0,
        'extra_75_minutes' => 0,
        'extra_100_minutes' => 0,
    ])->fresh();

    expect($result->worked_minutes)->toBe(510)
        ->and($result->scheduled_minutes)->toBe(480)
        ->and($result->recognized_minutes)->toBe(510)
        ->and($result->detected_overtime_minutes)->toBe(30)
        ->and($result->approved_overtime_minutes)->toBe(30)
        ->and($result->ordinary_minutes)->toBe(480)
        ->and($result->extra_25_minutes)->toBe(30)
        ->and((float) $result->extra_25_hours)->toBe(0.5);
});
