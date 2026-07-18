<?php

use App\Livewire\Nomina\Procesar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function setupPayPeriodForApproval(): array
{
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => 'processed',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
    ]);

    return [$company, $payPeriod, $employee, $admin];
}

test('approve changes pay period status from processed to approved', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->set('showApproveConfirm', true)
        ->call('approve')
        ->assertHasNoErrors();

    $payPeriod->refresh();

    expect($payPeriod->status)->toBe('approved')
        ->and($payPeriod->metadata['approved_at'])->not->toBeNull()
        ->and($payPeriod->metadata['approved_by'])->toBe($admin->id);
});

test('approve opens confirmation modal first', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->call('openApproveConfirm')
        ->assertSet('showApproveConfirm', true);

    expect($payPeriod->fresh()->status)->toBe('processed');
});

test('approve is blocked when pay period is already approved or exported', function (string $status) {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();
    $payPeriod->status = $status;
    $payPeriod->save();

    $this->actingAs($admin);
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

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->call('approve')
        ->assertStatus(403);
});

test('locked is true after approving', function () {
    [$company, $payPeriod, $employee, $admin] = setupPayPeriodForApproval();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Procesar::class, ['payPeriod' => $payPeriod])
        ->set('showApproveConfirm', true)
        ->call('approve');

    $component->assertSet('locked', true);
});
