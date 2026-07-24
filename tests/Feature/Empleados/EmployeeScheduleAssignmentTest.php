<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use Illuminate\Validation\ValidationException;

test('assigning a new schedule closes the previous assignment without overlap', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->create(['company_id' => $company->id]);
    $dayProfile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $nightProfile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);

    $first = $assigner->assign($employee, $dayProfile, '2026-07-01', 'Asignación inicial', $actor);
    $second = $assigner->assign($employee, $nightProfile, '2026-07-15', 'Cambio a turno nocturno', $actor);

    expect($first->fresh()->effective_to?->toDateString())->toBe('2026-07-14')
        ->and($second->effective_from->toDateString())->toBe('2026-07-15')
        ->and($second->effective_to)->toBeNull()
        ->and($second->assigned_by)->toBe($actor->id)
        ->and($second->reason)->toBe('Cambio a turno nocturno');
});

test('creates an employee and initial assigned schedule inside one payroll context', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $attributes = Employee::factory()->forCompany($company)->make()->getAttributes();

    $assignment = app(EmployeeScheduleAssigner::class)->createAndAssign(
        $attributes,
        $profile,
        '2026-07-01',
        'Jornada asignada al crear el empleado',
    );

    expect($assignment->employee->company_id)->toBe($company->id)
        ->and($assignment->employee->external_id)->toBe($attributes['external_id'])
        ->and($assignment->effective_from->toDateString())->toBe('2026-07-01');
});

test('updates an employee inside the same payroll context as a new assigned schedule', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create(['first_name' => 'Before']);
    $profiles = WorkScheduleProfile::factory()->count(2)->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);
    $assigner->assign($employee, $profiles[0], '2026-07-01', 'Jornada inicial');

    $assignment = $assigner->assign(
        $employee,
        $profiles[1],
        '2026-07-15',
        'Cambio de jornada y datos',
        mutateEmployee: fn (Employee $lockedEmployee) => $lockedEmployee->update(['first_name' => 'After']),
    );

    expect($employee->fresh()->first_name)->toBe('After')
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->effective_from->toDateString())->toBe('2026-07-15');
});

test('a backdated schedule assignment is bounded by its neighboring assignments', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profiles = WorkScheduleProfile::factory()->count(3)->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);

    $first = $assigner->assign($employee, $profiles[0], '2026-07-01', 'Turno inicial');
    $third = $assigner->assign($employee, $profiles[2], '2026-08-01', 'Turno de agosto');
    $second = $assigner->assign($employee, $profiles[1], '2026-07-15', 'Cobertura de julio');

    expect($first->fresh()->effective_to?->toDateString())->toBe('2026-07-14')
        ->and($second->effective_to?->toDateString())->toBe('2026-07-31')
        ->and($third->fresh()->effective_from->toDateString())->toBe('2026-08-01');
});

test('an employee cannot receive a schedule profile from another company', function () {
    $employee = Employee::factory()->forCompany()->create();
    $foreignProfile = WorkScheduleProfile::factory()->forCompany()->create();

    expect(fn () => app(EmployeeScheduleAssigner::class)->assign(
        $employee,
        $foreignProfile,
        '2026-07-01',
        'Asignación inválida',
    ))->toThrow(ValidationException::class);
});

test('an assignment requires a reason and a unique effective date', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);

    expect(fn () => $assigner->assign($employee, $profile, '2026-07-01', ''))
        ->toThrow(ValidationException::class);

    $assigner->assign($employee, $profile, '2026-07-01', 'Asignación inicial');

    expect(fn () => $assigner->assign($employee, $profile, '2026-07-01', 'Duplicada'))
        ->toThrow(ValidationException::class);
});

test('an assignment cannot change dates covered by a locked payroll period', function (string $status) {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profiles = WorkScheduleProfile::factory()->count(2)->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);
    $current = $assigner->assign($employee, $profiles[0], '2026-07-01', 'Asignación inicial');

    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-27',
        'status' => $status,
    ]);

    expect(fn () => $assigner->assign($employee, $profiles[1], '2026-07-15', 'Cambio retroactivo'))
        ->toThrow(ValidationException::class)
        ->and($current->fresh()->effective_to)->toBeNull()
        ->and($employee->scheduleAssignments()->count())->toBe(1);
})->with(['processing', 'processed', 'approved', 'exported', 'cancelled']);

test('an assignment may start after a locked payroll period', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profiles = WorkScheduleProfile::factory()->count(2)->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);
    $current = $assigner->assign($employee, $profiles[0], '2026-07-01', 'Asignación inicial');

    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'status' => 'exported',
    ]);

    $next = $assigner->assign($employee, $profiles[1], '2026-07-17', 'Cambio posterior');

    expect($current->fresh()->effective_to?->toDateString())->toBe('2026-07-16')
        ->and($next->effective_from->toDateString())->toBe('2026-07-17');
});

test('an assignment cannot repartition the final work date of a locked payroll period', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profiles = WorkScheduleProfile::factory()->count(2)->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);
    $current = $assigner->assign($employee, $profiles[0], '2026-07-01', 'Asignación inicial');

    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-20',
        'status' => 'exported',
    ]);

    expect(fn () => $assigner->assign($employee, $profiles[1], '2026-07-21', 'Cambio inmediato'))
        ->toThrow(ValidationException::class)
        ->and($current->fresh()->effective_to)->toBeNull()
        ->and($employee->scheduleAssignments()->count())->toBe(1);
});

test('a backdated assignment cannot repartition the first work date of a locked payroll period', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profiles = WorkScheduleProfile::factory()->count(3)->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);
    $first = $assigner->assign($employee, $profiles[0], '2026-07-01', 'Asignación inicial');
    $next = $assigner->assign($employee, $profiles[2], '2026-08-01', 'Turno de agosto');

    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-15',
        'status' => 'exported',
    ]);

    expect(fn () => $assigner->assign($employee, $profiles[1], '2026-07-15', 'Cobertura retroactiva'))
        ->toThrow(ValidationException::class)
        ->and($first->fresh()->effective_to?->toDateString())->toBe('2026-07-31')
        ->and($next->fresh()->effective_from->toDateString())->toBe('2026-08-01')
        ->and($employee->scheduleAssignments()->count())->toBe(2);
});
