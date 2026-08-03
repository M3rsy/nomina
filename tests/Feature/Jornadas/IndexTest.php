<?php

use App\Livewire\Jornadas\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\GeneralWorkSchedulePublisher;
use App\Services\Attendance\GeneralWorkScheduleResolver;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed(PermissionRoleSeeder::class);
});

test('activation publishes the next not-started general profile and reassigns current employees prospectively', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => null])->assignRole('super_admin');
    $previous = WorkScheduleProfile::factory()->forCompany($company)->create([
        'profile_key' => 'general', 'name' => 'Jornada general',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $previousAssignment = EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'work_schedule_profile_id' => $previous->id, 'effective_from' => '2026-07-01',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'status' => 'draft',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2027-01-01', 'end_date' => '2027-01-15', 'status' => 'draft',
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $component = Livewire::test(Index::class)
        ->assertSee('Motivo de activación')
        ->assertSee('Activar jornada general')
        ->call('activateGeneralProfile', 'Activation reason');
    $publication = WorkScheduleProfilePublication::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->where('payroll_policy_key', WorkScheduleProfilePublication::DURATION_FIRST_V2)
        ->sole();
    $profile = $publication->profile;
    expect($component->get('activationEffectiveFrom'))->toBe('2027-01-01')
        ->and($publication->effective_from->toDateString())->toBe('2027-01-01')
        ->and($profile->workSchedules)->toHaveCount(7)
        ->and($profile->workSchedules->where('is_working_day', true))->toHaveCount(6)
        ->and($profile->workSchedules->where('is_working_day', true)->every(
            fn ($schedule): bool => str_starts_with($schedule->start_time, '06:00')
                && str_starts_with($schedule->end_time, '14:00'),
        ))->toBeTrue()
        ->and($previous->publications()->sole()->effective_to->toDateString())->toBe('2027-01-01')
        ->and($previousAssignment->fresh()->effective_to->toDateString())->toBe('2026-12-31')
        ->and(app(GeneralWorkScheduleResolver::class)->resolve($company->id, '2026-12-31')->id)->toBe($previous->id)
        ->and(app(GeneralWorkScheduleResolver::class)->resolve($company->id, '2027-01-01')->id)->toBe($profile->id);
});

test('activation without a later configured pay period fails atomically', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => null]);
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);

    expect(fn () => app(GeneralWorkSchedulePublisher::class)->activate(
        $company, $actor, 'Activation without a later period', '2026-08-02 10:00:00',
    ))->toThrow(ValidationException::class)
        ->and(WorkScheduleProfile::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1);
});

test('equivalent activation retries return the existing publication without duplicate assignments', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => null]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $employee = Employee::factory()->forCompany($company)->create();
    EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_profile_id' => $profile->id,
        'effective_from' => '2026-07-01',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2027-01-01',
        'end_date' => '2027-01-15',
        'status' => 'draft',
    ]);
    $publisher = app(GeneralWorkSchedulePublisher::class);
    $first = $publisher->activate($company, $actor, 'Equivalent activation', '2026-08-02 10:00:00');
    $second = $publisher->activate($company, $actor, 'Equivalent activation', '2026-08-02 10:00:00');
    expect($second->id)->toBe($first->id)
        ->and(WorkScheduleProfile::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(2)
        ->and(WorkScheduleProfilePublication::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(2)
        ->and(EmployeeScheduleAssignment::withoutCompanyScope()->where('employee_id', $employee->id)->count())->toBe(2);

    expect(fn () => $publisher->activate(
        $company, $actor, 'Conflicting retry', '2026-08-02 10:00:00',
    ))->toThrow(ValidationException::class)
        ->and(WorkScheduleProfilePublication::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(2);
});

test('activation intersecting a locked period leaves publication and assignment history unchanged', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => null]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $employee = Employee::factory()->forCompany($company)->create();
    $assignment = EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_profile_id' => $profile->id,
        'effective_from' => '2026-07-01',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2027-01-01', 'end_date' => '2027-01-15', 'status' => 'draft',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2027-02-01', 'end_date' => '2027-02-15', 'status' => 'processed',
    ]);
    expect(fn () => app(GeneralWorkSchedulePublisher::class)->activate(
        $company, $actor, 'Locked activation', '2026-08-02 10:00:00',
    ))->toThrow(ValidationException::class)
        ->and($assignment->fresh()->effective_to)->toBeNull()
        ->and($profile->publications()->sole()->effective_to)->toBeNull()
        ->and(WorkScheduleProfile::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1);
});

test('company administrators cannot activate a general profile', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->for($company)->create()->assignRole('company_admin');
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    app(CurrentCompany::class)->set($company);

    Livewire::actingAs($actor)->test(Index::class)
        ->call('activateGeneralProfile', 'Unauthorized activation')
        ->assertForbidden();

    expect(WorkScheduleProfilePublication::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->where('payroll_policy_key', WorkScheduleProfilePublication::DURATION_FIRST_V2)
        ->count())->toBe(0);
});
