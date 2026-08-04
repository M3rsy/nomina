<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\GeneralWorkSchedulePublisher;
use Illuminate\Support\Facades\DB;
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

test('schedule assignment locks the company before the profile', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $assigner = app(EmployeeScheduleAssigner::class);
    $lockOrder = [];

    Company::retrieved(function (Company $retrieved) use ($company, &$lockOrder): void {
        if ($retrieved->is($company)) {
            $lockOrder[] = 'company';
        }
    });
    WorkScheduleProfile::retrieved(function (WorkScheduleProfile $retrieved) use ($profile, &$lockOrder): void {
        if ($retrieved->is($profile)) {
            $lockOrder[] = 'profile';
        }
    });

    $assigner->assign($employee, $profile, '2026-07-01', 'Canonical lock order');

    expect(array_slice($lockOrder, 0, 2))->toBe(['company', 'profile']);
});

test('schedule assignment uses one transaction without a savepoint', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $transactionLevel = null;
    $harnessLevel = DB::transactionLevel();

    app(EmployeeScheduleAssigner::class)->assign(
        $employee,
        $profile,
        '2026-07-01',
        'Single transaction',
        mutateEmployee: function () use (&$transactionLevel): void {
            $transactionLevel = DB::transactionLevel();
        },
    );

    expect($transactionLevel)->toBe($harnessLevel + 1);
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
    $employeeSnapshot = $employee->fresh()->getAttributes();
    $profileSnapshot = $foreignProfile->fresh()->getAttributes();

    expect(fn () => app(EmployeeScheduleAssigner::class)->assign(
        $employee,
        $foreignProfile,
        '2026-07-01',
        'Asignación inválida',
    ))->toThrow(ValidationException::class)
        ->and(EmployeeScheduleAssignment::withoutCompanyScope()->count())->toBe(0)
        ->and($employee->fresh()->getAttributes())->toBe($employeeSnapshot)
        ->and($foreignProfile->fresh()->getAttributes())->toBe($profileSnapshot);
});

test('a stale form cannot assign a retired schedule profile', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $retiredProfile = WorkScheduleProfile::factory()->forCompany($company)->create([
        'is_active' => false,
        'retired_at' => now(),
    ]);
    $employeeSnapshot = $employee->fresh()->getAttributes();
    $profileSnapshot = $retiredProfile->fresh()->getAttributes();

    expect(fn () => app(EmployeeScheduleAssigner::class)->assign(
        $employee,
        $retiredProfile,
        '2026-07-01',
        'Formulario abierto antes del retiro.',
    ))->toThrow(ValidationException::class)
        ->and(EmployeeScheduleAssignment::withoutCompanyScope()->count())->toBe(0)
        ->and($employee->fresh()->getAttributes())->toBe($employeeSnapshot)
        ->and($retiredProfile->fresh()->getAttributes())->toBe($profileSnapshot);
});

test('employee creation cannot use a stale retired schedule profile', function () {
    $company = Company::factory()->create();
    $retiredProfile = WorkScheduleProfile::factory()->forCompany($company)->create([
        'is_active' => false,
        'retired_at' => now(),
    ]);
    $attributes = Employee::factory()->forCompany($company)->make()->getAttributes();
    $profileSnapshot = $retiredProfile->fresh()->getAttributes();

    expect(fn () => app(EmployeeScheduleAssigner::class)->createAndAssign(
        $attributes,
        $retiredProfile,
        '2026-07-01',
        'Formulario de alta abierto antes del retiro.',
    ))->toThrow(ValidationException::class)
        ->and(Employee::withoutCompanyScope()->count())->toBe(0)
        ->and(EmployeeScheduleAssignment::withoutCompanyScope()->count())->toBe(0)
        ->and($retiredProfile->fresh()->getAttributes())->toBe($profileSnapshot);
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

    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-27',
        'status' => $status,
    ]);
    $assignmentSnapshot = $current->fresh()->getAttributes();
    $employeeSnapshot = $employee->fresh()->getAttributes();
    $profileSnapshots = $profiles->map(fn (WorkScheduleProfile $profile): array => $profile->fresh()->getAttributes())->all();
    $periodSnapshot = $period->fresh()->getAttributes();

    expect(fn () => $assigner->assign($employee, $profiles[1], '2026-07-15', 'Cambio retroactivo'))
        ->toThrow(ValidationException::class)
        ->and($current->fresh()->effective_to)->toBeNull()
        ->and(EmployeeScheduleAssignment::withoutCompanyScope()->count())->toBe(1)
        ->and($current->fresh()->getAttributes())->toBe($assignmentSnapshot)
        ->and($employee->fresh()->getAttributes())->toBe($employeeSnapshot)
        ->and($profiles->map(fn (WorkScheduleProfile $profile): array => $profile->fresh()->getAttributes())->all())->toBe($profileSnapshots)
        ->and($period->fresh()->getAttributes())->toBe($periodSnapshot);
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

test('new employees resolve the exact general profile before on and after activation', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id]);
    $previous = WorkScheduleProfile::factory()->forCompany($company)->create([
        'profile_key' => 'general', 'name' => 'Jornada general',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2027-01-01', 'end_date' => '2027-01-15', 'status' => 'draft',
    ]);
    $publication = app(GeneralWorkSchedulePublisher::class)->activate(
        $company, $actor, 'Activate date-effective profile', '2026-08-02 10:00:00',
    );
    $assigner = app(EmployeeScheduleAssigner::class);
    $dates = ['2026-12-31', '2027-01-01', '2027-02-01'];

    $assignments = collect($dates)->map(function (string $date) use ($assigner, $company, $actor) {
        $attributes = Employee::factory()->forCompany($company)->make(['hired_at' => $date])->getAttributes();

        return $assigner->createAndAssignGeneral(
            $attributes, $date, 'Jornada general vigente al contratar', $actor,
        );
    });

    expect($assignments->pluck('work_schedule_profile_id')->all())->toBe([
        $previous->id,
        $publication->profile_id,
        $publication->profile_id,
    ]);
});

test('new employee assignment fails closed when the general profile is missing or ambiguous', function (bool $ambiguous) {
    $company = Company::factory()->create();
    if ($ambiguous) {
        WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
        $overlap = WorkScheduleProfile::withoutEvents(fn () => WorkScheduleProfile::factory()->forCompany($company)
            ->create(['profile_key' => 'general', 'version' => 2]));
        WorkScheduleProfilePublication::withoutCompanyScope()->create([
            'company_id' => $company->id, 'profile_key' => 'general', 'profile_id' => $overlap->id,
            'payroll_policy_key' => WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1,
            'effective_from' => '1970-01-01', 'definition_hash' => str_repeat('a', 64),
            'request_key' => str_repeat('b', 64), 'payload_hash' => str_repeat('c', 64),
            'reason' => 'Ambiguous fixture',
        ]);
    }
    $attributes = Employee::factory()->forCompany($company)->make()->getAttributes();

    expect(fn () => app(EmployeeScheduleAssigner::class)->createAndAssignGeneral(
        $attributes, '2026-08-02', 'Exact-one general profile required',
    ))->toThrow(ValidationException::class)
        ->and(Employee::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(0);
})->with(['missing' => false, 'ambiguous' => true]);
