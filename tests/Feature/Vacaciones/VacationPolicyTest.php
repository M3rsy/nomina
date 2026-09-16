<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vacation;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionRoleSeeder::class);
});

function vacationForCompany(Company $company): Vacation
{
    $employee = Employee::factory()->forCompany($company)->create();

    return Vacation::factory()->for($company)->for($employee)->create();
}

test('super admin cannot access vacations without an active company context', function () {
    $company = Company::factory()->create();
    $vacation = vacationForCompany($company);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set(null);

    expect($superAdmin->can('viewAny', Vacation::class))->toBeFalse()
        ->and($superAdmin->can('create', Vacation::class))->toBeFalse()
        ->and($superAdmin->can('view', $vacation))->toBeFalse()
        ->and($superAdmin->can('cancel', $vacation))->toBeFalse();
});

test('vacation instance abilities require the active company to match', function () {
    $activeCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $activeVacation = vacationForCompany($activeCompany);
    $otherVacation = vacationForCompany($otherCompany);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set($activeCompany);

    expect($superAdmin->can('viewAny', Vacation::class))->toBeTrue()
        ->and($superAdmin->can('create', Vacation::class))->toBeTrue()
        ->and($superAdmin->can('view', $activeVacation))->toBeTrue()
        ->and($superAdmin->can('cancel', $activeVacation))->toBeTrue()
        ->and($superAdmin->can('view', $otherVacation))->toBeFalse()
        ->and($superAdmin->can('cancel', $otherVacation))->toBeFalse();
});

test('vacation abilities reject inactive company context', function () {
    $inactiveCompany = Company::factory()->inactive()->create();
    $vacation = vacationForCompany($inactiveCompany);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set($inactiveCompany);

    expect($superAdmin->can('viewAny', Vacation::class))->toBeFalse()
        ->and($superAdmin->can('create', Vacation::class))->toBeFalse()
        ->and($superAdmin->can('view', $vacation))->toBeFalse()
        ->and($superAdmin->can('cancel', $vacation))->toBeFalse();
});

test('company admin keeps vacation abilities only for its active company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $vacation = vacationForCompany($company);
    $otherVacation = vacationForCompany($otherCompany);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    expect($admin->can('viewAny', Vacation::class))->toBeTrue()
        ->and($admin->can('create', Vacation::class))->toBeTrue()
        ->and($admin->can('view', $vacation))->toBeTrue()
        ->and($admin->can('cancel', $vacation))->toBeTrue()
        ->and($admin->can('view', $otherVacation))->toBeFalse()
        ->and($admin->can('cancel', $otherVacation))->toBeFalse();

    app(CurrentCompany::class)->set($otherCompany);

    expect($admin->can('viewAny', Vacation::class))->toBeFalse()
        ->and($admin->can('create', Vacation::class))->toBeFalse();
});
