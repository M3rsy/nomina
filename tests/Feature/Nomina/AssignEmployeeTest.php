<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
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

test('saveAssign assigns a single employee to a raw mark and corrects status', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $rawMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '12345',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openAssignModal', $rawMark->id)
        ->set('assignEmployeeId', $employee->id)
        ->call('saveAssign')
        ->assertHasNoErrors();

    $rawMark->refresh();

    expect($rawMark->employee_id)->toBe($employee->id)
        ->and($rawMark->status)->toBe('corrected')
        ->and($rawMark->metadata)->not->toBeNull();

    $revisions = $rawMark->metadata['revisions'] ?? [];
    expect($revisions)->toHaveCount(1);
    expect($revisions[0]['action'])->toBe('assign_employee');
    expect($revisions[0]['user_id'])->toBe($admin->id);
    expect($revisions[0]['previous_employee_id'])->toBeNull();
    expect($revisions[0]['new_employee_id'])->toBe($employee->id);
});

test('assignApplyAll assigns employee to every raw mark with same external id and null employee', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $targetMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '99999',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);

    $secondTarget = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '99999',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-06 08:00:00'),
    ]);

    $differentExternal = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '11111',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-07 08:00:00'),
    ]);

    $alreadyAssigned = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '99999',
        'employee_id' => Employee::factory()->forCompany($company)->create()->id,
        'status' => 'valid',
        'event_at' => Carbon::parse('2026-01-08 08:00:00'),
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openAssignModal', $targetMark->id)
        ->set('assignEmployeeId', $employee->id)
        ->set('assignApplyAll', true)
        ->call('saveAssign')
        ->assertHasNoErrors();

    $targetMark->refresh();
    $secondTarget->refresh();
    $differentExternal->refresh();
    $alreadyAssigned->refresh();

    expect($targetMark->employee_id)->toBe($employee->id)
        ->and($targetMark->status)->toBe('corrected')
        ->and($secondTarget->employee_id)->toBe($employee->id)
        ->and($secondTarget->status)->toBe('corrected')
        ->and($differentExternal->employee_id)->toBeNull()
        ->and($alreadyAssigned->employee_id)->not->toBe($employee->id)
        ->and($alreadyAssigned->status)->toBe('valid');
});

test('cannot assign employee from another company to raw mark', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($companyA)->create();
    $file = UploadedFile::factory()->forCompany($companyA)->forPayPeriod($payPeriod)->create();
    $employeeB = Employee::factory()->forCompany($companyB)->create();
    $adminA = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    $rawMark = RawMark::factory()->forCompany($companyA)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '12345',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);

    $this->actingAs($adminA);
    app(CurrentCompany::class)->set($companyA);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openAssignModal', $rawMark->id)
        ->set('assignEmployeeId', $employeeB->id)
        ->call('saveAssign')
        ->assertHasErrors('assignEmployeeId');

    $rawMark->refresh();

    expect($rawMark->employee_id)->toBeNull()
        ->and($rawMark->status)->toBe('unknown_employee');
});
