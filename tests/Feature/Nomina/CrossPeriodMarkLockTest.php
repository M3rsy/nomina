<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    $this->company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($this->company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    $this->employee = Employee::factory()->forCompany($this->company)->create();
    app(EmployeeScheduleAssigner::class)->assign($this->employee, $profile, '2026-07-01', 'Turno nocturno');

    $this->lockedPeriod = PayPeriod::factory()->forCompany($this->company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-20',
        'status' => 'exported',
    ]);
    $this->openPeriod = PayPeriod::factory()->forCompany($this->company)->create([
        'start_date' => '2026-07-21',
        'end_date' => '2026-07-31',
        'status' => 'validating',
    ]);
    $lockedFile = UploadedFile::factory()->forCompany($this->company)->forPayPeriod($this->lockedPeriod)->create();
    $this->openFile = UploadedFile::factory()->forCompany($this->company)->forPayPeriod($this->openPeriod)->create();

    RawMark::factory()->forCompany($this->company)->forPayPeriod($this->lockedPeriod)
        ->forUploadedFile($lockedFile)->forEmployee($this->employee)->create([
            'event_at' => '2026-07-20 18:00:00',
            'status' => 'valid',
        ]);
    $this->exit = RawMark::factory()->forCompany($this->company)->forPayPeriod($this->openPeriod)
        ->forUploadedFile($this->openFile)->forEmployee($this->employee)->create([
            'event_at' => '2026-07-21 06:00:00',
            'status' => 'valid',
        ]);
    $this->admin = User::factory()->forCompany($this->company)->create()->assignRole('company_admin');

    $this->actingAs($this->admin);
    app(CurrentCompany::class)->set($this->company);
});

test('an exit stored in the next period cannot change a locked overnight work date', function () {

    Livewire::test(Revisar::class, ['payPeriod' => $this->openPeriod])
        ->set('editRawMarkId', $this->exit->id)
        ->set('editEventAt', '2026-07-21 06:15:00')
        ->set('editReason', 'Corregir la hora observada')
        ->call('saveEditRawMark')
        ->assertHasErrors(['raw_mark']);

    expect($this->exit->fresh()->event_at->toDateTimeString())->toBe('2026-07-21 06:00:00')
        ->and($this->exit->fresh()->status)->toBe('valid');
});

test('an open mark cannot be moved into a locked overnight work date', function () {
    $safeMark = RawMark::factory()->forCompany($this->company)->forPayPeriod($this->openPeriod)
        ->forUploadedFile($this->openFile)->forEmployee($this->employee)->create([
            'event_at' => '2026-07-22 12:00:00',
            'status' => 'valid',
        ]);

    Livewire::test(Revisar::class, ['payPeriod' => $this->openPeriod])
        ->set('editRawMarkId', $safeMark->id)
        ->set('editEventAt', '2026-07-21 05:45:00')
        ->set('editReason', 'Corregir la hora observada')
        ->call('saveEditRawMark')
        ->assertHasErrors(['raw_mark']);

    expect($safeMark->fresh()->event_at->toDateTimeString())->toBe('2026-07-22 12:00:00');
});

test('an overnight exit remains editable while every affected period is open', function () {
    $this->lockedPeriod->update(['status' => 'validating']);

    Livewire::test(Revisar::class, ['payPeriod' => $this->openPeriod])
        ->set('editRawMarkId', $this->exit->id)
        ->set('editEventAt', '2026-07-21 06:15:00')
        ->set('editReason', 'Corregir la hora observada')
        ->call('saveEditRawMark')
        ->assertHasNoErrors();

    expect($this->exit->fresh()->event_at->toDateTimeString())->toBe('2026-07-21 06:15:00')
        ->and($this->exit->fresh()->status)->toBe('corrected');
});

test('an exit stored in the next period cannot be deleted from a locked overnight work date', function () {
    Livewire::test(Revisar::class, ['payPeriod' => $this->openPeriod])
        ->set('deleteRawMarkId', $this->exit->id)
        ->set('deleteReason', 'La marca no corresponde a un hecho real')
        ->call('deleteRawMark')
        ->assertHasErrors(['raw_mark']);

    expect($this->exit->fresh()->status)->toBe('valid');
});

test('an exit stored in the next period cannot be corrected for a locked overnight work date', function () {
    Livewire::test(Revisar::class, ['payPeriod' => $this->openPeriod])
        ->call('openCorrectRawMark', $this->exit->id)
        ->set('correctReason', 'Validación manual del estado')
        ->call('markCorrected')
        ->assertHasErrors(['raw_mark']);

    expect($this->exit->fresh()->status)->toBe('valid');
});

test('an unknown exit cannot be assigned into a locked overnight work date', function () {
    $this->exit->update([
        'employee_id' => null,
        'status' => 'unknown_employee',
    ]);

    Livewire::test(Revisar::class, ['payPeriod' => $this->openPeriod])
        ->set('assignRawMarkId', $this->exit->id)
        ->set('assignEmployeeId', $this->employee->id)
        ->set('assignApplyAll', false)
        ->set('assignReason', 'Código verificado contra el legajo')
        ->call('saveAssign')
        ->assertHasErrors(['raw_mark']);

    expect($this->exit->fresh()->employee_id)->toBeNull()
        ->and($this->exit->fresh()->status)->toBe('unknown_employee');
});

test('assigning every matching code rolls back when one mark belongs to a locked work date', function () {
    $this->exit->update([
        'employee_id' => null,
        'status' => 'unknown_employee',
    ]);
    $safeMark = RawMark::factory()->forCompany($this->company)->forPayPeriod($this->openPeriod)
        ->forUploadedFile($this->openFile)->create([
            'employee_external_id' => $this->exit->employee_external_id,
            'event_at' => '2026-07-22 12:00:00',
            'status' => 'unknown_employee',
        ]);

    Livewire::test(Revisar::class, ['payPeriod' => $this->openPeriod])
        ->set('assignRawMarkId', $safeMark->id)
        ->set('assignEmployeeId', $this->employee->id)
        ->set('assignApplyAll', true)
        ->set('assignReason', 'Código verificado para todas las marcas')
        ->call('saveAssign')
        ->assertHasErrors(['raw_mark']);

    expect($safeMark->fresh()->employee_id)->toBeNull()
        ->and($safeMark->fresh()->status)->toBe('unknown_employee')
        ->and($this->exit->fresh()->employee_id)->toBeNull();
});
