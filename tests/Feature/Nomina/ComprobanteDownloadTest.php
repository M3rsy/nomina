<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function setupStubScenario(): array
{
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => 'exported',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
        'entry_at' => Carbon::parse('2026-01-05 08:00:00'),
        'exit_at' => Carbon::parse('2026-01-05 17:00:00'),
        'worked_hours' => 9.0,
        'ordinary_hours' => 8.0,
        'extra_25_hours' => 1,
    ]);

    return [$company, $payPeriod, $employee, $admin];
}

test('company admin can download comprobante for own employee', function () {
    [$company, $payPeriod, $employee, $admin] = setupStubScenario();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = $this->get("/nomina/{$payPeriod->id}/empleado/{$employee->id}/comprobante");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->assertHeader('content-disposition', 'attachment; filename="Comprobante '.$employee->external_id.' '.$payPeriod->slug.'.xlsx"');
});

test('comprobante is unavailable before the official payroll export', function (string $status) {
    [$company, $payPeriod, $employee, $admin] = setupStubScenario();
    $payPeriod->update(['status' => $status]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/empleado/{$employee->id}/comprobante")
        ->assertStatus(Response::HTTP_CONFLICT);
})->with([
    'processed' => ['processed'],
    'approved' => ['approved'],
    'cancelled' => ['cancelled'],
]);

test('comprobante download rejects employee from another company', function () {
    [$company, $payPeriod, $employee, $admin] = setupStubScenario();
    $companyB = Company::factory()->create();
    $employeeB = Employee::factory()->forCompany($companyB)->create();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/empleado/{$employeeB->id}/comprobante")
        ->assertForbidden();
});

test('user without payroll export permission cannot download comprobante', function () {
    [$company, $payPeriod, $employee, $admin] = setupStubScenario();
    $admin->roles->first()->revokePermissionTo('payroll.export');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/empleado/{$employee->id}/comprobante")
        ->assertForbidden();
});

test('comprobante download rejects access to other company pay period', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => 'exported',
    ]);
    $employeeB = Employee::factory()->forCompany($companyB)->create();
    $adminA = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($companyA);

    $this->actingAs($adminA)
        ->get("/nomina/{$payPeriodB->id}/empleado/{$employeeB->id}/comprobante")
        ->assertForbidden();
});
