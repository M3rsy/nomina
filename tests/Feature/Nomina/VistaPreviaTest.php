<?php

use App\Livewire\Nomina\Procesar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function setupProcessedPayPeriod(): array
{
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => 'processed',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    return [$company, $payPeriod, $employee, $admin];
}

test('company admin can render procesar page for processed pay period', function () {
    [$company, $payPeriod, $employee, $admin] = setupProcessedPayPeriod();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/procesar")
        ->assertOk()
        ->assertSee('Procesar nómina');
});

test('procesar page renders payroll results grouped by employee', function () {
    [$company, $payPeriod, $employee, $admin] = setupProcessedPayPeriod();

    $result = PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
        'entry_at' => Carbon::parse('2026-01-05 08:00:00'),
        'exit_at' => Carbon::parse('2026-01-05 17:00:00'),
        'worked_hours' => 9.0,
        'ordinary_hours' => 8.0,
        'extra_25_hours' => 0.5,
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('results', function ($results) use ($result) {
            return $results->count() === 1 && $results->first()->id === $result->id;
        });
});

test('procesar summary card shows totals for employees and hours', function () {
    [$company, $payPeriod, $employee, $admin] = setupProcessedPayPeriod();

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
        'ordinary_hours' => 0.02,
        'extra_25_hours' => 0.02,
        'ordinary_minutes' => 1,
        'extra_25_minutes' => 1,
    ]);
    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-06',
        'ordinary_hours' => 0.02,
        'extra_25_hours' => 0.02,
        'ordinary_minutes' => 1,
        'extra_25_minutes' => 1,
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('summary', function ($summary) {
            expect($summary)->toMatchArray([
                'total_employees' => 1,
                'total_records' => 2,
                'ordinary_hours' => 2 / 60,
                'extra_25_hours' => 2 / 60,
            ]);

            return true;
        });
});

test('procesar rows derive displayed hours from canonical minutes', function () {
    [$company, $payPeriod, $employee, $admin] = setupProcessedPayPeriod();

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
        'worked_hours' => 9.0,
        'ordinary_hours' => 8.0,
        'extra_25_hours' => 0.5,
        'worked_minutes' => 1,
        'ordinary_minutes' => 1,
        'extra_25_minutes' => 1,
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->assertSeeHtml('>0.02</td>')
        ->assertDontSee('9.00')
        ->assertDontSee('8.00');
});

test('procesar page filters by employee', function () {
    [$company, $payPeriod, $employee, $admin] = setupProcessedPayPeriod();
    $otherEmployee = Employee::factory()->forCompany($company)->create();

    $targetResult = PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
    ]);

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($otherEmployee)->create([
        'date' => '2026-01-06',
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->set('employee_id', $employee->id)
        ->assertViewHas('results', function ($results) use ($targetResult) {
            return $results->count() === 1 && $results->first()->id === $targetResult->id;
        });
});

test('company admin cannot access procesar page of other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create(['status' => 'processed']);
    $adminA = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($companyA);

    $this->actingAs($adminA)
        ->get("/nomina/{$payPeriodB->id}/procesar")
        ->assertForbidden();
});

test('super admin cannot access procesar page outside active company', function () {
    $activeCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $otherPayPeriod = PayPeriod::factory()->forCompany($otherCompany)->create(['status' => 'processed']);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    app(CurrentCompany::class)->set($activeCompany);

    $this->actingAs($superAdmin)
        ->get("/nomina/{$otherPayPeriod->id}/procesar")
        ->assertForbidden();
});

test('user without payroll process permission cannot access procesar page', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'processed']);
    $user = User::factory()->forCompany($company)->create();

    app(CurrentCompany::class)->set($company);

    $this->actingAs($user)
        ->get("/nomina/{$payPeriod->id}/procesar")
        ->assertForbidden();
});

test('procesar redirects to revisar when pay period is not processed', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/procesar")
        ->assertRedirect("/nomina/{$payPeriod->id}/revisar");
});
