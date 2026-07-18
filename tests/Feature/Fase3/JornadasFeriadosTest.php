<?php

use App\Livewire\Feriados\Index as HolidaysIndex;
use App\Livewire\Jornadas\Index as WorkSchedulesIndex;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
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
