<?php

use App\Models\Company;
use App\Models\Employee;
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
