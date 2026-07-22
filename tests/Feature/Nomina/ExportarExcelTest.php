<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function setupExportScenario(string $status = 'approved'): array
{
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => $status,
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

test('company admin can download excel export for approved pay period', function () {
    [$company, $payPeriod, $employee, $admin] = setupExportScenario('approved');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = $this->get("/nomina/{$payPeriod->id}/excel");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->assertHeader('content-disposition', 'attachment; filename="Asistencia 20260105 hasta 20260111.xlsx"');
});

test('excel export sets pay period status to exported', function () {
    [$company, $payPeriod, $employee, $admin] = setupExportScenario('approved');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/excel")
        ->assertOk();

    expect($payPeriod->fresh()->status)->toBe('exported');
});

test('excel export cannot overwrite a period changed before the transition lock', function () {
    [$company, $payPeriod, $employee, $admin] = setupExportScenario('approved');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $raceTriggered = false;
    $armed = true;

    DB::connection()->beforeStartingTransaction(function () use (&$armed, &$raceTriggered, $payPeriod): void {
        if (! $armed || $raceTriggered) {
            return;
        }

        $raceTriggered = true;

        DB::table('pay_periods')
            ->where('id', $payPeriod->id)
            ->update(['status' => 'validating']);
    });

    $this->get("/nomina/{$payPeriod->id}/excel")
        ->assertStatus(Response::HTTP_CONFLICT);
    $armed = false;

    expect($raceTriggered)->toBeTrue()
        ->and($payPeriod->fresh()->status)->toBe('validating');
});

test('excel export is idempotent and does not downgrade from exported', function () {
    [$company, $payPeriod, $employee, $admin] = setupExportScenario('exported');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/excel")
        ->assertOk();

    expect($payPeriod->fresh()->status)->toBe('exported');
});

test('excel export rejects a pay period outside the approved workflow', function (string $status) {
    [$company, $payPeriod, $employee, $admin] = setupExportScenario($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/excel")
        ->assertStatus(Response::HTTP_CONFLICT);

    expect($payPeriod->fresh()->status)->toBe($status);
})->with([
    'draft' => ['draft'],
    'validating' => ['validating'],
    'ready' => ['ready'],
    'processing' => ['processing'],
    'processed' => ['processed'],
    'cancelled' => ['cancelled'],
]);

test('company admin cannot download excel export of other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => 'processed',
    ]);
    $adminA = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($companyA);

    $this->actingAs($adminA)
        ->get("/nomina/{$payPeriodB->id}/excel")
        ->assertForbidden();
});

test('user without payroll export permission cannot download excel', function () {
    [$company, $payPeriod, $employee, $admin] = setupExportScenario('processed');
    $admin->roles->first()->revokePermissionTo('payroll.export');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $this->get("/nomina/{$payPeriod->id}/excel")
        ->assertForbidden();
});
