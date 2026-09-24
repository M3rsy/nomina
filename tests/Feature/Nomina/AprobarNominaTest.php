<?php

use App\Livewire\Nomina\Procesar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Pest\TestSuite;
use Tests\TestCase;

function aprobarNominaTestCase(): TestCase
{
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('The current Pest test case is unavailable.');
    }

    return $test;
}

beforeEach(function () {
    aprobarNominaTestCase()->seed(PermissionRoleSeeder::class);
});

function setupPayPeriodForApproval(): array
{
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => 'processed',
        'current_result_generation' => 1,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
        'day_snapshot' => ['evidence' => 'frozen'],
        'employee_name' => 'Frozen result',
    ]);

    return [$company, $payPeriod, $employee, $admin];
}

test('approve changes pay period status from processed to approved', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();

    aprobarNominaTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->call('requestApprovalConfirmation')
        ->assertSet('showApprovalConfirmation', true)
        ->call('approve')
        ->assertHasNoErrors();

    $payPeriod->refresh();

    expect($payPeriod->status)->toBe('approved')
        ->and($payPeriod->metadata['approved_at'])->not->toBeNull()
        ->and($payPeriod->metadata['approved_by'])->toBe($admin->id);
});

test('approve cannot overwrite a period reopened before the transition lock', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();

    aprobarNominaTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Procesar::class, ['payPeriod' => $payPeriod]);
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

    $component
        ->call('requestApprovalConfirmation')
        ->call('approve')
        ->assertHasNoErrors();
    $armed = false;

    expect($raceTriggered)->toBeTrue()
        ->and($payPeriod->fresh()->status)->toBe('validating')
        ->and($payPeriod->fresh()->payrollResults()->count())->toBe(1);
});

test('approval requires an accessible confirmation before mutating payroll', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();

    aprobarNominaTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->assertSee('Aprobar nómina')
        ->call('approve')
        ->assertSet('showApprovalConfirmation', false)
        ->assertHasNoErrors()
        ->call('requestApprovalConfirmation')
        ->assertSet('showApprovalConfirmation', true)
        ->assertSee('Confirmar aprobación')
        ->assertSee('Esta acción registra quién aprobó la nómina y habilita la exportación.')
        ->call('cancelApprovalConfirmation')
        ->assertSet('showApprovalConfirmation', false);

    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and($payPeriod->fresh()->metadata['approved_at'] ?? null)->toBeNull();
});

test('results review reads only the current frozen generation and exposes evidence without mutations', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();
    $current = PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->sole();
    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-06',
        'result_generation' => 0,
        'employee_name' => 'Stale result',
    ]);

    aprobarNominaTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->assertSee('Frozen result')
        ->assertDontSee('Stale result')
        ->call('showEvidence', $current->id)
        ->assertSee('Evidencia congelada')
        ->assertSee('frozen');
});

test('approve is blocked when pay period is already approved or exported', function (string $status) {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();
    $payPeriod->status = $status;
    $payPeriod->save();

    aprobarNominaTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->call('approve')
        ->assertHasNoErrors();

    expect($payPeriod->fresh()->status)->toBe($status);
})->with([
    'approved' => ['approved'],
    'exported' => ['exported'],
]);

test('user without payroll approve permission cannot approve', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();
    $admin->roles->first()->revokePermissionTo('payroll.approve');

    aprobarNominaTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->call('requestApprovalConfirmation')
        ->assertStatus(403);
});

test('locked is true after approving', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();

    aprobarNominaTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->call('requestApprovalConfirmation')
        ->call('approve');

    $component->assertSet('locked', true);
});

test('excel export is exposed only after payroll approval', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();

    aprobarNominaTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->assertDontSee('Generar Excel')
        ->call('requestApprovalConfirmation')
        ->call('approve')
        ->assertSee('Generar Excel');
});
