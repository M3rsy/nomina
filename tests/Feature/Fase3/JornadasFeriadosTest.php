<?php

use App\Livewire\Feriados\Index as HolidaysIndex;
use App\Livewire\Jornadas\Index as WorkSchedulesIndex;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Database\Seeders\WorkScheduleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('work schedule policy denies company admin A from updating company B schedule', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->for($companyA)->create()->assignRole('company_admin');
    $scheduleB = WorkSchedule::factory()->forCompany($companyB)->create(['day_of_week' => 1]);

    $this->assertFalse($adminA->can('update', $scheduleB));
});

test('work schedule policy allows super admin to update any schedule', function () {
    $company = Company::factory()->create();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');
    $schedule = WorkSchedule::factory()->forCompany($company)->create(['day_of_week' => 1]);

    $this->assertTrue($superAdmin->can('update', $schedule));
});

test('holiday policy denies company admin A from managing company B holiday', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->for($companyA)->create()->assignRole('company_admin');
    $holidayB = Holiday::factory()->forCompany($companyB)->create(['date' => '2026-09-15']);

    $this->assertFalse($adminA->can('update', $holidayB));
    $this->assertFalse($adminA->can('delete', $holidayB));
});

test('company admin can access jornadas page of own company', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);
    $this->actingAs($admin)
        ->get('/jornadas')
        ->assertOk();
});

test('super admin without active company sees empty jornadas page', function () {
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    app(CurrentCompany::class)->set(null);
    $this->actingAs($superAdmin)
        ->get('/jornadas')
        ->assertOk();
});

test('company admin can save work schedules for own company', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    Livewire::actingAs($admin)
        ->test(WorkSchedulesIndex::class)
        ->call('save')
        ->assertSet('showSuccess', true);

    expect(WorkSchedule::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(7);
});

test('saving a schedule profile creates an audited immutable version', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create()->assignRole('company_admin');

    $this->seed(WorkScheduleSeeder::class);
    $originalProfile = WorkScheduleProfile::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->sole();
    $originalMonday = $originalProfile->workSchedules()->where('day_of_week', 1)->sole();

    app(CurrentCompany::class)->set($company);

    Livewire::actingAs($admin)
        ->test(WorkSchedulesIndex::class)
        ->set('schedules.1.start_time', '18:00')
        ->set('schedules.1.end_time', '06:00')
        ->set('changeReason', 'Nuevo horario operativo')
        ->call('save')
        ->assertSet('showSuccess', true)
        ->assertHasNoErrors();

    $newProfile = WorkScheduleProfile::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->where('is_active', true)
        ->sole();

    expect($originalProfile->fresh()->is_active)->toBeFalse()
        ->and($originalMonday->fresh()->start_time)->toStartWith('06:00')
        ->and($newProfile->version)->toBe(2)
        ->and($newProfile->created_by)->toBe($admin->id)
        ->and($newProfile->change_reason)->toBe('Nuevo horario operativo')
        ->and($newProfile->workSchedules()->where('day_of_week', 1)->sole()->start_time)->toStartWith('18:00');

    Livewire::actingAs($admin)->test(WorkSchedulesIndex::class)
        ->set('schedules.1.start_time', '06:00')
        ->set('schedules.1.end_time', '06:00')
        ->set('changeReason', 'Horario inválido')
        ->call('save')
        ->assertHasErrors(['schedules.1.end_time']);
});

test('company admin can duplicate the selected schedule into a reusable profile', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create()->assignRole('company_admin');

    $this->seed(WorkScheduleSeeder::class);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::actingAs($admin)
        ->test(WorkSchedulesIndex::class)
        ->set('newProfileName', 'Guardia nocturna')
        ->call('createProfile')
        ->assertHasNoErrors()
        ->assertSet('showCreateProfile', false);

    $profile = WorkScheduleProfile::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->where('profile_key', 'guardia-nocturna')
        ->sole();

    expect($profile->name)->toBe('Guardia nocturna')
        ->and($profile->version)->toBe(1)
        ->and($profile->created_by)->toBe($admin->id)
        ->and($profile->workSchedules)->toHaveCount(7)
        ->and($profile->workSchedules()->where('day_of_week', 1)->sole()->start_time)->toStartWith('06:00')
        ->and($component->get('selectedProfileId'))->toBe($profile->id)
        ->and(WorkScheduleProfile::withoutCompanyScope()->where('company_id', $company->id)->where('is_active', true)->count())->toBe(2);
});

test('company admin can save custom overtime bands in work schedule JSON', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create()->assignRole('company_admin');
    $customBands = '[{"start":"06:00","end":"12:00","extra_percent":0},{"start":"12:00","end":"18:00","extra_percent":25},{"start":"18:00","end":"00:00","extra_percent":50},{"start":"00:00","end":"06:00","extra_percent":75}]';

    app(CurrentCompany::class)->set($company);

    Livewire::actingAs($admin)
        ->test(WorkSchedulesIndex::class)
        ->set('schedules.1.banding_json', $customBands)
        ->call('save')
        ->assertSet('showSuccess', true)
        ->assertHasNoErrors();

    $schedule = WorkSchedule::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->where('day_of_week', 1)
        ->first();

    expect($schedule)
        ->not->toBeNull()
        ->and($schedule->banding_json)->toBeArray()
        ->and($schedule->banding_json[0]['start'])->toBe('06:00')
        ->and($schedule->banding_json[3]['end'])->toBe('06:00');
});

test('company admin gets validation error for invalid overtime band JSON', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    Livewire::actingAs($admin)
        ->test(WorkSchedulesIndex::class)
        ->set('schedules.1.banding_json', '[{"start":"06:00",')
        ->call('save')
        ->assertHasErrors(['schedules.1.banding_json']);
});

test('company admin can access feriados page of own company', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);
    $this->actingAs($admin)
        ->get('/feriados')
        ->assertOk();
});

test('company admin can create a holiday for own company', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    Livewire::actingAs($admin)
        ->test(HolidaysIndex::class)
        ->set('formDate', '2026-12-25')
        ->set('formName', 'Navidad')
        ->set('formDescription', 'Feriado nacional')
        ->call('save')
        ->assertSet('showCreateModal', false);

    expect(Holiday::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1);
});

test('work schedule seeder creates seven rows per company', function () {
    $company = Company::factory()->create();

    $this->seed(WorkScheduleSeeder::class);

    expect(WorkSchedule::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(7);
});
