<?php

use App\Livewire\Empresas\Create;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);

    $this->superAdmin = User::factory()->create(['company_id' => null]);
    $this->superAdmin->assignRole('super_admin');
    $this->actingAs($this->superAdmin);
});

test('creating a company provisions its canonical default schedule', function () {
    Livewire::test(Create::class)
        ->set('name', 'Seguridad Central')
        ->set('legal_id', 'RTN-NEW-001')
        ->call('save')
        ->assertHasNoErrors();

    $company = Company::where('legal_id', 'RTN-NEW-001')->sole();
    $profile = WorkScheduleProfile::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->sole();
    $schedules = $profile->workSchedules->keyBy('day_of_week');

    expect($profile->profile_key)->toBe('general')
        ->and($profile->version)->toBe(1)
        ->and($profile->is_active)->toBeTrue()
        ->and($schedules)->toHaveCount(7)
        ->and(substr($schedules[1]->start_time, 0, 5))->toBe('06:00')
        ->and(substr($schedules[1]->end_time, 0, 5))->toBe('14:00')
        ->and(substr($schedules[6]->start_time, 0, 5))->toBe('08:00')
        ->and(substr($schedules[6]->end_time, 0, 5))->toBe('12:00')
        ->and($schedules[0]->is_working_day)->toBeFalse();
});
