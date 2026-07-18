<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JustifiedAbsence;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function setupLockedRevisar(string $status): array
{
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => $status,
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $rawMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->forEmployee($employee)->create([
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
        'status' => 'valid',
    ]);

    return [$company, $payPeriod, $file, $employee, $admin, $rawMark];
}

test('revisar sets locked property for approved exported and cancelled statuses', function (string $status) {
    [$company, $payPeriod, $file, $employee, $admin] = setupLockedRevisar($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod]);

    expect($component->instance()->locked)->toBeTrue();
})->with([
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);

test('edit actions are no-ops when pay period is locked', function (string $status) {
    [$company, $payPeriod, $file, $employee, $admin, $rawMark] = setupLockedRevisar($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openEditRawMark', $rawMark->id)
        ->assertSet('showEditModal', false)
        ->set('editRawMarkId', $rawMark->id)
        ->set('editEventAt', '2026-01-05 09:00:00')
        ->call('saveEditRawMark')
        ->assertHasNoErrors();

    $rawMark->refresh();
    expect($rawMark->status)->toBe('valid');
})->with([
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);

test('delete actions are no-ops when pay period is locked', function (string $status) {
    [$company, $payPeriod, $file, $employee, $admin, $rawMark] = setupLockedRevisar($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openDeleteRawMark', $rawMark->id)
        ->assertSet('showDeleteModal', false)
        ->set('deleteRawMarkId', $rawMark->id)
        ->call('deleteRawMark')
        ->assertHasNoErrors();

    $rawMark->refresh();
    expect($rawMark->status)->toBe('valid');
})->with([
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);

test('markCorrected is no-op when pay period is locked', function (string $status) {
    [$company, $payPeriod, $file, $employee, $admin, $rawMark] = setupLockedRevisar($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('markCorrected', $rawMark->id)
        ->assertHasNoErrors();

    $rawMark->refresh();
    expect($rawMark->status)->toBe('valid');
})->with([
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);

test('justifyAbsence is no-op when pay period is locked', function (string $status) {
    [$company, $payPeriod, $file, $employee, $admin] = setupLockedRevisar($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employee->id, '2026-01-06', 'permission')
        ->assertHasNoErrors();

    expect(JustifiedAbsence::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
})->with([
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);

test('assign actions are no-ops when pay period is locked', function (string $status) {
    [$company, $payPeriod, $file, $employee, $admin, $rawMark] = setupLockedRevisar($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openAssignModal', $rawMark->id)
        ->assertSet('showAssignModal', false)
        ->set('assignRawMarkId', $rawMark->id)
        ->set('assignEmployeeId', $employee->id)
        ->call('saveAssign')
        ->assertHasNoErrors();

    $rawMark->refresh();
    expect($rawMark->employee_id)->toBe($employee->id);
})->with([
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);
