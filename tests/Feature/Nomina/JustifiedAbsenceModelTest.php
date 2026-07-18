<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\JustifiedAbsence;
use App\Models\PayPeriod;
use App\Services\CurrentCompany;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionRoleSeeder::class);
});

test('justified absence belongs to company, pay period, employee and justifier', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    $absence = JustifiedAbsence::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-15',
        'reason' => 'permission',
    ]);

    expect($absence->company_id)->toBe($company->id)
        ->and($absence->payPeriod->id)->toBe($payPeriod->id)
        ->and($absence->employee->id)->toBe($employee->id)
        ->and($absence->date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($absence->reason)->toBe('permission');
});

test('justified absences are scoped by current company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    JustifiedAbsence::factory()->forCompany($companyA)->create();
    JustifiedAbsence::factory()->forCompany($companyB)->create();

    app(CurrentCompany::class)->set($companyA);

    expect(JustifiedAbsence::count())->toBe(1);
    expect(JustifiedAbsence::first()->company_id)->toBe($companyA->id);
});

test('duplicate justified absence for same employee and date violates unique constraint', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    JustifiedAbsence::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-20',
    ]);

    expect(fn () => JustifiedAbsence::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-20',
    ]))->toThrow(QueryException::class);
});
