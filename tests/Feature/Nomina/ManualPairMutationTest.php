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
use App\Services\Attendance\ManualRawMarkRecorder;
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    $this->company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($this->company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'base_ordinary_hours' => 8,
    ]);
    $this->employee = Employee::factory()->forCompany($this->company)->create();
    $this->otherEmployee = Employee::factory()->forCompany($this->company)->create();
    app(EmployeeScheduleAssigner::class)->assign($this->employee, $profile, '2026-07-01', 'Jornada diurna');
    app(EmployeeScheduleAssigner::class)->assign($this->otherEmployee, $profile, '2026-07-01', 'Jornada diurna');
    $this->period = PayPeriod::factory()->forCompany($this->company)->create([
        'start_date' => '2026-07-16',
        'end_date' => '2026-07-31',
        'status' => 'validating',
    ]);
    $this->file = UploadedFile::factory()->forCompany($this->company)->forPayPeriod($this->period)->create();
    $this->admin = User::factory()->forCompany($this->company)->create()->assignRole('company_admin');
    $this->actingAs($this->admin);
    app(CurrentCompany::class)->set($this->company);

    $this->observedEntry = observedMutationMark($this, $this->employee, '2026-07-20 06:00:00');
    $this->manualExit = app(ManualRawMarkRecorder::class)->record(
        $this->period,
        $this->employee,
        '2026-07-20',
        '2026-07-20 14:00:00',
        'El reloj omitió la salida real',
        $this->admin,
    );
});

test('cannot edit a manual fact into an occurrence that already has two observed marks', function () {
    observedMutationMark($this, $this->employee, '2026-07-27 06:00:00');
    observedMutationMark($this, $this->employee, '2026-07-27 14:00:00');

    Livewire::test(Revisar::class, ['payPeriod' => $this->period])
        ->set('editRawMarkId', $this->manualExit->id)
        ->set('editEventAt', '2026-07-27 12:00:00')
        ->set('editReason', 'Corrección de fecha informada')
        ->call('saveEditRawMark')
        ->assertHasErrors(['raw_mark']);

    $resolver = app(ShiftOccurrenceResolver::class);

    expect($this->manualExit->fresh()->event_at->toDateTimeString())->toBe('2026-07-20 14:00:00')
        ->and($resolver->resolve($this->employee, '2026-07-20')->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($resolver->resolve($this->employee, '2026-07-27')->status)->toBe(ShiftOccurrence::RESOLVED);
});

test('cannot reassign the observed half and leave an active manual fact alone', function () {
    Livewire::test(Revisar::class, ['payPeriod' => $this->period])
        ->call('openAssignModal', $this->observedEntry->id)
        ->set('assignEmployeeId', $this->otherEmployee->id)
        ->set('assignReason', 'Corrección de empleado informado')
        ->call('saveAssign')
        ->assertHasErrors(['raw_mark']);

    $resolver = app(ShiftOccurrenceResolver::class);

    expect($this->observedEntry->fresh()->employee_id)->toBe($this->employee->id)
        ->and($this->manualExit->fresh()->employee_id)->toBe($this->employee->id)
        ->and($resolver->resolve($this->employee, '2026-07-20')->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($resolver->resolve($this->otherEmployee, '2026-07-20')->status)->toBe(ShiftOccurrence::NO_MARKS);
});

test('cannot reassign a manual fact as a third mark for another employee', function () {
    observedMutationMark($this, $this->otherEmployee, '2026-07-20 06:00:00');
    observedMutationMark($this, $this->otherEmployee, '2026-07-20 14:00:00');

    Livewire::test(Revisar::class, ['payPeriod' => $this->period])
        ->call('openAssignModal', $this->manualExit->id)
        ->set('assignEmployeeId', $this->otherEmployee->id)
        ->set('assignReason', 'Corrección de empleado informado')
        ->call('saveAssign')
        ->assertHasErrors(['raw_mark']);

    $resolver = app(ShiftOccurrenceResolver::class);

    expect($this->manualExit->fresh()->employee_id)->toBe($this->employee->id)
        ->and($resolver->resolve($this->employee, '2026-07-20')->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($resolver->resolve($this->otherEmployee, '2026-07-20')->status)->toBe(ShiftOccurrence::RESOLVED);
});

test('can reassign a manual fact when both affected occurrences remain valid', function () {
    observedMutationMark($this, $this->otherEmployee, '2026-07-20 06:00:00');

    Livewire::test(Revisar::class, ['payPeriod' => $this->period])
        ->call('openAssignModal', $this->manualExit->id)
        ->set('assignEmployeeId', $this->otherEmployee->id)
        ->set('assignReason', 'Empleado corregido contra el reporte del supervisor')
        ->call('saveAssign')
        ->assertHasNoErrors();

    $resolver = app(ShiftOccurrenceResolver::class);

    expect($this->manualExit->fresh()->employee_id)->toBe($this->otherEmployee->id)
        ->and($resolver->resolve($this->employee, '2026-07-20')->status)->toBe(ShiftOccurrence::MISSING_PAIR)
        ->and($resolver->resolve($this->otherEmployee, '2026-07-20')->status)->toBe(ShiftOccurrence::RESOLVED);
});

function observedMutationMark(object $context, Employee $employee, string $eventAt): RawMark
{
    return RawMark::factory()
        ->forCompany($context->company)
        ->forPayPeriod($context->period)
        ->forUploadedFile($context->file)
        ->forEmployee($employee)
        ->create([
            'employee_external_id' => $employee->external_id,
            'event_at' => $eventAt,
            'status' => 'valid',
            'source' => 'glg',
        ]);
}
