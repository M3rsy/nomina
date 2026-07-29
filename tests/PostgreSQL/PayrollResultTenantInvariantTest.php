<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const PAYROLL_RESULT_TENANT_INVARIANTS_MIGRATION = 'database/migrations/2026_07_24_000001_enforce_payroll_result_tenant_invariants.php';

function rollbackPayrollResultTenantInvariantsMigration(): void
{
    Artisan::call('migrate:rollback', [
        '--path' => PAYROLL_RESULT_TENANT_INVARIANTS_MIGRATION,
        '--force' => true,
    ]);
}

function payrollResultConstraintDefinitions(): array
{
    return DB::table('pg_constraint')
        ->selectRaw('conname, pg_get_constraintdef(oid) as definition')
        ->whereIn('conname', [
            'employees_company_id_id_unique',
            'pay_periods_company_id_id_unique',
            'payroll_results_company_employee_foreign',
            'payroll_results_company_id_foreign',
            'payroll_results_company_pay_period_foreign',
            'payroll_results_employee_id_foreign',
            'payroll_results_pay_period_id_foreign',
        ])
        ->orderBy('conname')
        ->pluck('definition', 'conname')
        ->all();
}

function payrollResultSqlState(Closure $operation): ?string
{
    try {
        $operation();
    } catch (QueryException $exception) {
        return $exception->getCode();
    }

    return null;
}

function insertPayrollResult(Company $company, PayPeriod $period, Employee $employee, string $date = '2026-01-05'): void
{
    DB::table('payroll_results')->insert([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'date' => $date,
    ]);
}

test('accepts a payroll result whose tenant references are coherent', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    insertPayrollResult($company, $period, $employee);

    expect(DB::table('payroll_results')->where([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
    ])->exists())->toBeTrue();
});

test('rejects a payroll result linked to a tenant reference from another company', function (string $foreignKey) {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $otherPeriod = PayPeriod::factory()->forCompany($otherCompany)->create();

    $period = $foreignKey === 'pay_period_id' ? $otherPeriod : $period;
    $employee = $foreignKey === 'employee_id' ? $otherEmployee : $employee;

    expect(payrollResultSqlState(
        fn () => insertPayrollResult($company, $period, $employee)
    ))->toBe('23503');
})->with([
    'pay period' => 'pay_period_id',
    'employee' => 'employee_id',
]);

test('rejects changing a payroll result to a tenant reference from another company', function (string $foreignKey) {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $otherPeriod = PayPeriod::factory()->forCompany($otherCompany)->create();
    insertPayrollResult($company, $period, $employee);

    $foreignId = $foreignKey === 'pay_period_id' ? $otherPeriod->id : $otherEmployee->id;

    expect(payrollResultSqlState(
        fn () => DB::table('payroll_results')->update([$foreignKey => $foreignId])
    ))->toBe('23503')
        ->and(DB::table('payroll_results')->value($foreignKey))
        ->toBe($foreignKey === 'pay_period_id' ? $period->id : $employee->id);
})->with([
    'pay period' => 'pay_period_id',
    'employee' => 'employee_id',
]);

test('aborts before changing the catalog when a historical tenant reference is inconsistent', function (string $foreignKey) {
    rollbackPayrollResultTenantInvariantsMigration();

    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $otherPeriod = PayPeriod::factory()->forCompany($otherCompany)->create();
    $period = $foreignKey === 'pay_period_id' ? $otherPeriod : $period;
    $employee = $foreignKey === 'employee_id' ? $otherEmployee : $employee;
    insertPayrollResult($company, $period, $employee);
    $constraintsBefore = payrollResultConstraintDefinitions();

    $exception = null;

    try {
        Artisan::call('migrate', ['--force' => true]);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->not->toBeNull()
        ->and($exception?->getMessage())
        ->toBe('Cannot enforce payroll result tenant invariants: historical cross-company references exist.')
        ->and(payrollResultConstraintDefinitions())->toBe($constraintsBefore)
        ->and(DB::table('payroll_results')->count())->toBe(1);
})->with([
    'pay period' => 'pay_period_id',
    'employee' => 'employee_id',
]);

test('rolls back and reapplies the tenant constraints safely', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    insertPayrollResult($company, $period, $employee);

    rollbackPayrollResultTenantInvariantsMigration();
    $rolledBack = payrollResultConstraintDefinitions();

    expect($rolledBack)->toHaveKeys([
        'payroll_results_company_id_foreign',
        'payroll_results_employee_id_foreign',
        'payroll_results_pay_period_id_foreign',
    ])->not->toHaveKeys([
        'employees_company_id_id_unique',
        'pay_periods_company_id_id_unique',
        'payroll_results_company_employee_foreign',
        'payroll_results_company_pay_period_foreign',
    ]);

    Artisan::call('migrate', ['--force' => true]);
    $reapplied = payrollResultConstraintDefinitions();

    expect($reapplied)->toHaveKeys([
        'employees_company_id_id_unique',
        'pay_periods_company_id_id_unique',
        'payroll_results_company_employee_foreign',
        'payroll_results_company_id_foreign',
        'payroll_results_company_pay_period_foreign',
    ])->not->toHaveKeys([
        'payroll_results_employee_id_foreign',
        'payroll_results_pay_period_id_foreign',
    ])
        ->and($reapplied['payroll_results_company_employee_foreign'])->toContain('ON DELETE CASCADE')
        ->and($reapplied['payroll_results_company_id_foreign'])->toContain('ON DELETE CASCADE')
        ->and($reapplied['payroll_results_company_pay_period_foreign'])->toContain('ON DELETE CASCADE')
        ->and(DB::table('payroll_results')->count())->toBe(1);
});
