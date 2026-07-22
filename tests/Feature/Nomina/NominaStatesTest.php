<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JustifiedAbsence;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayPeriodReopener;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
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

test('revisar sets locked property for immutable payroll statuses', function (string $status) {
    [$company, $payPeriod, $file, $employee, $admin] = setupLockedRevisar($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod]);

    expect($component->instance()->locked)->toBeTrue();
})->with([
    'processing' => ['processing'],
    'processed' => ['processed'],
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
        ->set('editReason', 'Corregir la hora observada')
        ->call('saveEditRawMark')
        ->assertHasNoErrors();

    $rawMark->refresh();
    expect($rawMark->status)->toBe('valid');
})->with([
    'processing' => ['processing'],
    'processed' => ['processed'],
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
    'processing' => ['processing'],
    'processed' => ['processed'],
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);

test('markCorrected is no-op when pay period is locked', function (string $status) {
    [$company, $payPeriod, $file, $employee, $admin, $rawMark] = setupLockedRevisar($status);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openCorrectRawMark', $rawMark->id)
        ->assertSet('showCorrectModal', false)
        ->set('correctRawMarkId', $rawMark->id)
        ->set('correctReason', 'Validación manual del estado')
        ->call('markCorrected')
        ->assertHasNoErrors();

    $rawMark->refresh();
    expect($rawMark->status)->toBe('valid');
})->with([
    'processing' => ['processing'],
    'processed' => ['processed'],
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
    'processing' => ['processing'],
    'processed' => ['processed'],
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
    'processing' => ['processing'],
    'processed' => ['processed'],
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);

test('mutations recheck the current period status after the review was opened', function () {
    [$company, $payPeriod, $file, $employee, $admin, $rawMark] = setupLockedRevisar('validating');
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);
    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openCorrectRawMark', $rawMark->id)
        ->set('correctReason', 'Validación manual del estado');

    $payPeriod->update(['status' => 'processing']);
    $component->call('markCorrected')->assertHasNoErrors();

    expect($rawMark->fresh()->status)->toBe('valid');
});

test('processed payroll can be reopened with an audited reason and stale results are removed', function () {
    [$company, $payPeriod, $file, $employee, $admin] = setupLockedRevisar('processed');
    PayrollResult::factory()->forCompany($company)->for($payPeriod)->for($employee)->create();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('reopenReason', 'Corregir una marca observada')
        ->call('reopenProcessedPeriod')
        ->assertHasNoErrors()
        ->assertSet('locked', false);

    $reopening = $payPeriod->fresh()->metadata['reopenings'][0];

    expect($payPeriod->fresh()->status)->toBe('validating')
        ->and($payPeriod->payrollResults()->count())->toBe(0)
        ->and($reopening)->toMatchArray([
            'from_status' => 'processed',
            'to_status' => 'validating',
            'reason' => 'Corregir una marca observada',
            'user_id' => $admin->id,
            'invalidated_results' => 1,
        ])
        ->and($reopening['at'])->not->toBeEmpty();
});

test('processed payroll cannot be reopened without a reason', function () {
    [$company, $payPeriod, $file, $employee, $admin] = setupLockedRevisar('processed');
    PayrollResult::factory()->forCompany($company)->for($payPeriod)->for($employee)->create();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('reopenReason', '  ')
        ->call('reopenProcessedPeriod')
        ->assertHasErrors(['reopenReason' => 'required']);

    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and($payPeriod->payrollResults()->count())->toBe(1);
});

test('a manager cannot reopen another company payroll', function () {
    [$company, $payPeriod, $file, $employee] = setupLockedRevisar('processed');
    PayrollResult::factory()->forCompany($company)->for($payPeriod)->for($employee)->create();
    $foreignManager = User::factory()->forCompany(Company::factory()->create())->create()
        ->assignRole('company_admin');

    expect(fn () => app(PayPeriodReopener::class)->reopen(
        $payPeriod,
        'Intento fuera de empresa',
        $foreignManager,
    ))->toThrow(AuthorizationException::class);

    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and($payPeriod->payrollResults()->count())->toBe(1);
});
