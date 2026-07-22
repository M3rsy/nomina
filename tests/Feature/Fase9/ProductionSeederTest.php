<?php

use App\Models\Company;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use Database\Seeders\ProductionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('production seeder creates only the first company and super admin', function () {
    $this->seed(ProductionSeeder::class);

    expect(Company::count())->toBe(1);
    expect(Company::where('slug', 'empresa-principal')->exists())->toBeTrue();
    expect(Company::where('slug', 'empresa-a')->exists())->toBeFalse();
    expect(Company::where('slug', 'empresa-b')->exists())->toBeFalse();

    $superAdmin = User::where('email', 'admin@nomina.test')->first();
    expect($superAdmin)->not->toBeNull();
    expect($superAdmin->hasRole('super_admin'))->toBeTrue();
});

test('production seeder installs permissions and roles', function () {
    $this->seed(ProductionSeeder::class);

    expect(Permission::count())->toBeGreaterThan(0);
    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();
    expect(Role::where('name', 'company_admin')->exists())->toBeTrue();
});

test('production seeder provisions the canonical default schedule', function () {
    $this->seed(ProductionSeeder::class);

    $company = Company::where('slug', 'empresa-principal')->sole();
    $profile = WorkScheduleProfile::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->sole();
    $schedules = $profile->workSchedules->keyBy('day_of_week');

    expect($profile->profile_key)->toBe('general')
        ->and($profile->version)->toBe(1)
        ->and($schedules)->toHaveCount(7)
        ->and(substr($schedules[1]->start_time, 0, 5))->toBe('06:00')
        ->and(substr($schedules[1]->end_time, 0, 5))->toBe('14:00')
        ->and(substr($schedules[6]->start_time, 0, 5))->toBe('08:00')
        ->and(substr($schedules[6]->end_time, 0, 5))->toBe('12:00')
        ->and($schedules[0]->is_working_day)->toBeFalse();
});

test('production seeder is idempotent', function () {
    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class);

    expect(Company::count())->toBe(1);
    expect(User::where('email', 'admin@nomina.test')->count())->toBe(1);
});

test('production seeder respects SUPER_ADMIN_EMAIL env', function () {
    $customEmail = 'prod-admin@example.com';

    putenv("SUPER_ADMIN_EMAIL={$customEmail}");
    config()->set('app.env', 'production');

    $this->seed(ProductionSeeder::class);

    expect(User::where('email', $customEmail)->exists())->toBeTrue();

    putenv('SUPER_ADMIN_EMAIL');
});
