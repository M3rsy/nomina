<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\Vacation;
use App\Models\VacationBalanceMovement;
use App\Models\VacationDay;
use App\Models\WorkSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const VACATION_TENANT_INVARIANTS_MIGRATION = 'database/migrations/2026_09_12_000001_enforce_vacation_tenant_invariants.php';

function rollbackVacationTenantInvariantsMigration(): void
{
    Artisan::call('migrate:rollback', ['--path' => VACATION_TENANT_INVARIANTS_MIGRATION, '--force' => true]);
}

function vacationTenantConstraintDefinitions(): array
{
    return DB::table('pg_constraint')
        ->selectRaw('conname, pg_get_constraintdef(oid) as definition')
        ->whereIn('conname', [
            'vacations_employee_id_foreign', 'vacations_company_employee_foreign',
            'vacation_days_vacation_id_foreign', 'vacation_days_company_vacation_employee_foreign',
            'vacation_balance_movements_vacation_id_foreign', 'vacation_balance_company_vacation_employee_foreign',
            'payroll_results_vacation_id_foreign', 'payroll_results_company_vacation_employee_foreign',
            'payroll_results_vacation_day_id_foreign', 'payroll_results_company_day_vacation_employee_foreign',
        ])->orderBy('conname')->pluck('definition', 'conname')->all();
}

function vacationTenantSqlState(Closure $operation): ?string
{
    try {
        $operation();
    } catch (QueryException $exception) {
        return $exception->getCode();
    }

    return null;
}

function vacationGraph(?Company $company = null): array
{
    $company ??= Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $vacation = Vacation::factory()->for($company)->for($employee)->create();
    $day = VacationDay::factory()->for($vacation)->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'work_date' => '2026-02-02',
    ]);

    return [$company, $employee, $vacation, $day];
}

function insertVacationPayrollResult(Company $company, Employee $employee, ?Vacation $vacation, ?VacationDay $day): void
{
    $period = PayPeriod::factory()->forCompany($company)->create(['start_date' => '2026-02-01', 'end_date' => '2026-02-28']);
    DB::table('payroll_results')->insert([
        'company_id' => $company->id, 'pay_period_id' => $period->id, 'employee_id' => $employee->id,
        'date' => '2026-02-02', 'day_type' => $vacation ? 'paid_vacation' : 'attendance',
        'vacation_id' => $vacation?->id, 'vacation_day_id' => $day?->id,
    ]);
}

test('accepts coherent vacation tenant references and optional identities', function () {
    [$company, $employee, $vacation, $day] = vacationGraph();

    VacationBalanceMovement::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'vacation_id' => $vacation->id, 'vacation_day_id' => $day->id,
        'type' => VacationBalanceMovement::VACATION_CONSUMPTION,
    ]);
    VacationBalanceMovement::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    insertVacationPayrollResult($company, $employee, $vacation, $day);
    insertVacationPayrollResult($company, $employee, null, null);

    expect(DB::table('vacation_balance_movements')->count())->toBe(2)
        ->and(DB::table('payroll_results')->count())->toBe(2);
});

test('rejects cross-tenant vacation references at the PostgreSQL boundary', function (string $table, string $column) {
    [$company, $employee] = vacationGraph();
    [$otherCompany, $otherEmployee, $otherVacation, $otherDay] = vacationGraph();

    $state = match ($column) {
        'employee_id' => [$column => $otherEmployee->id],
        'vacation_id' => [$column => $otherVacation->id],
        'vacation_day_id' => [$column => $otherDay->id],
    };

    $operation = match ($table) {
        'vacations' => fn () => Vacation::factory()->for($company)->create($state),
        'vacation_days' => fn () => VacationDay::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, ...$state]),
        'vacation_balance_movements' => fn () => VacationBalanceMovement::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, ...$state]),
        'payroll_results' => fn () => insertVacationPayrollResult($company, $employee, $column === 'vacation_id' ? $otherVacation : null, $column === 'vacation_day_id' ? $otherDay : null),
    };

    expect(vacationTenantSqlState($operation))->toBe('23503');
})->with([
    'vacation employee' => ['vacations', 'employee_id'],
    'day vacation' => ['vacation_days', 'vacation_id'],
    'balance vacation' => ['vacation_balance_movements', 'vacation_id'],
    'balance day' => ['vacation_balance_movements', 'vacation_day_id'],
    'payroll vacation' => ['payroll_results', 'vacation_id'],
    'payroll day' => ['payroll_results', 'vacation_day_id'],
]);

test('rejects optional vacation day tenant references from another company', function (string $column, Closure $foreignId) {
    [$company, $employee, $vacation] = vacationGraph();
    $foreign = $foreignId($company);

    expect(vacationTenantSqlState(fn () => VacationDay::factory()->for($vacation)->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-04-01',
        $column => $foreign,
    ])))->toBe('23503');
})->with([
    'assignment' => ['employee_schedule_assignment_id', fn (Company $company) => EmployeeScheduleAssignment::factory()->create()->id],
    'schedule' => ['work_schedule_id', fn (Company $company) => WorkSchedule::factory()->create()->id],
    'publication' => ['work_schedule_profile_publication_id', function (Company $company) {
        $profileId = DB::table('work_schedule_profiles')->max('id') + 1;
        $profileKey = 'vacation-'.uniqid();
        $companyId = Company::factory()->create()->id;
        DB::table('work_schedule_profiles')->insert([
            'id' => $profileId,
            'company_id' => $companyId,
            'profile_key' => $profileKey,
            'name' => 'Vacation invariant profile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('work_schedule_profile_publications')->insertGetId([
            'company_id' => $companyId,
            'profile_key' => $profileKey,
            'profile_id' => $profileId,
            'payroll_policy_key' => 'schedule-overlap-v1',
            'effective_from' => '2100-01-01',
            'definition_hash' => str_repeat('a', 64),
            'request_key' => str_repeat('b', 64),
            'payload_hash' => str_repeat('c', 64),
            'reason' => 'Vacation invariant test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }],
]);

test('rejects mismatched vacation and day identities for the same tenant employee', function (string $table) {
    [$company, $employee, $vacation] = vacationGraph();
    $otherVacation = Vacation::factory()->for($company)->for($employee)->create(['start_date' => '2026-03-01', 'end_date' => '2026-03-01']);
    $otherDay = VacationDay::factory()->for($otherVacation)->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'work_date' => '2026-03-01']);

    expect(vacationTenantSqlState(match ($table) {
        'balance' => fn () => VacationBalanceMovement::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'vacation_id' => $vacation->id, 'vacation_day_id' => $otherDay->id]),
        'payroll' => fn () => insertVacationPayrollResult($company, $employee, $vacation, $otherDay),
    }))->toBe('23503');
})->with(['balance', 'payroll']);

test('aborts before changing the catalog when historical vacation tenants are inconsistent', function () {
    rollbackVacationTenantInvariantsMigration();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    Vacation::factory()->for($company)->create(['employee_id' => $otherEmployee->id]);
    $constraintsBefore = vacationTenantConstraintDefinitions();

    $exception = null;
    try {
        Artisan::call('migrate', ['--path' => VACATION_TENANT_INVARIANTS_MIGRATION, '--force' => true]);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception?->getMessage())->toBe('Cannot enforce vacation tenant invariants: vacations.employee_id has cross-company reference at id 1.')
        ->and(vacationTenantConstraintDefinitions())->toBe($constraintsBefore)
        ->and(DB::table('vacations')->count())->toBe(1);
});

test('rolls back and reapplies the vacation tenant constraints safely', function () {
    vacationGraph();
    rollbackVacationTenantInvariantsMigration();
    $rolledBack = vacationTenantConstraintDefinitions();

    expect($rolledBack)->toHaveKeys(['vacations_employee_id_foreign', 'payroll_results_vacation_id_foreign'])
        ->not->toHaveKeys(['vacations_company_employee_foreign', 'payroll_results_company_vacation_employee_foreign']);

    Artisan::call('migrate', ['--path' => VACATION_TENANT_INVARIANTS_MIGRATION, '--force' => true]);
    $reapplied = vacationTenantConstraintDefinitions();

    expect($reapplied)->toHaveKeys(['vacations_company_employee_foreign', 'payroll_results_company_vacation_employee_foreign'])
        ->not->toHaveKeys(['vacations_employee_id_foreign', 'payroll_results_vacation_id_foreign']);
});
