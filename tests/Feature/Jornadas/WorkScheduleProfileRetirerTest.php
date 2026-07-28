<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\WorkScheduleProfileRetirer;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-02-16 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('retiring a schedule profile reassigns current and future references without rewriting history', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => null]);
    $source = WorkScheduleProfile::factory()->forCompany($company)->create(['name' => 'Jornada diurna']);
    $replacement = WorkScheduleProfile::factory()->forCompany($company)->create(['name' => 'Jornada reemplazante']);
    $unrelated = WorkScheduleProfile::factory()->forCompany($company)->create(['name' => 'Jornada futura']);

    $sameDayEmployee = Employee::factory()->forCompany($company)->create();
    $historical = EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $sameDayEmployee->id,
        'work_schedule_profile_id' => $source->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-02-15',
    ]);
    $sameDay = EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $sameDayEmployee->id,
        'work_schedule_profile_id' => $source->id,
        'effective_from' => '2026-02-16',
        'effective_to' => '2026-02-28',
    ]);
    $future = EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $sameDayEmployee->id,
        'work_schedule_profile_id' => $source->id,
        'effective_from' => '2026-03-01',
        'effective_to' => null,
    ]);

    $inactiveEmployee = Employee::factory()->forCompany($company)->create(['is_active' => false]);
    $current = EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $inactiveEmployee->id,
        'work_schedule_profile_id' => $source->id,
        'effective_from' => '2025-12-01',
        'effective_to' => '2026-03-31',
    ]);
    $unrelatedFuture = EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $inactiveEmployee->id,
        'work_schedule_profile_id' => $unrelated->id,
        'effective_from' => '2026-04-01',
        'effective_to' => null,
    ]);

    $retired = app(WorkScheduleProfileRetirer::class)->retireAndReassign(
        $source,
        $replacement,
        'El equipo de seguridad cambia de jornada.',
        $actor,
    );

    expect($retired->is_active)->toBeFalse()
        ->and($retired->retired_at?->isSameDay(CarbonImmutable::today()))->toBeTrue()
        ->and($retired->retired_by)->toBe($actor->id)
        ->and($retired->retirement_reason)->toBe('El equipo de seguridad cambia de jornada.')
        ->and($retired->replacement_profile_id)->toBe($replacement->id)
        ->and($historical->fresh()->work_schedule_profile_id)->toBe($source->id)
        ->and($sameDay->fresh()->work_schedule_profile_id)->toBe($replacement->id)
        ->and($future->fresh()->work_schedule_profile_id)->toBe($replacement->id)
        ->and($current->fresh()->effective_to->toDateString())->toBe('2026-02-15')
        ->and($unrelatedFuture->fresh()->work_schedule_profile_id)->toBe($unrelated->id);

    $replacementToday = EmployeeScheduleAssignment::withoutCompanyScope()
        ->where('employee_id', $inactiveEmployee->id)
        ->whereDate('effective_from', '2026-02-16')
        ->firstOrFail();

    expect($replacementToday->work_schedule_profile_id)->toBe($replacement->id)
        ->and($replacementToday->effective_to->toDateString())->toBe('2026-03-31');
});

test('a locked payroll period rolls back the complete profile retirement', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => null]);
    $source = WorkScheduleProfile::factory()->forCompany($company)->create();
    $replacement = WorkScheduleProfile::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $assignment = EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_profile_id' => $source->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
        'status' => 'processed',
    ]);

    expect(fn () => app(WorkScheduleProfileRetirer::class)->retireAndReassign(
        $source,
        $replacement,
        'Sustitución de jornada activa.',
        $actor,
    ))->toThrow(ValidationException::class);

    expect($source->fresh()->is_active)->toBeTrue()
        ->and($source->fresh()->retired_at)->toBeNull()
        ->and($assignment->fresh()->work_schedule_profile_id)->toBe($source->id)
        ->and($assignment->fresh()->effective_to)->toBeNull();
});
