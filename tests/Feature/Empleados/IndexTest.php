<?php

use App\Livewire\Empleados\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('super admin paginates all company employees with stable ordering', function () {
    $companies = Company::factory()->count(2)->create();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $employees = collect(range(1, 11))->map(fn (int $number) => Employee::factory()
        ->forCompany($companies[$number % 2])
        ->create([
            'external_id' => sprintf('EMP-%02d', $number),
            'first_name' => 'Name '.$number,
            'last_name' => 'Surname '.$number,
        ]));

    $employees->reverse()->each->update([
        'first_name' => 'Tied',
        'last_name' => 'Employee',
    ]);

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set(null);

    Livewire::test(Index::class)
        ->assertSeeInOrder($employees->take(10)->pluck('external_id')->all())
        ->assertDontSee('EMP-11')
        ->assertSeeHtml('wire:click="nextPage(\'page\')"')
        ->call('setPage', 2)
        ->assertSee('EMP-11')
        ->assertDontSee('EMP-01')
        ->assertSeeHtml('wire:click="previousPage(\'page\')"');
});

test('company employee filters reset page two and remain tenant scoped', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    Employee::factory()->count(11)->forCompany($company)
        ->sequence(fn ($sequence) => [
            'external_id' => sprintf('EMP-%02d', $sequence->index + 1),
            'first_name' => 'Employee',
            'last_name' => sprintf('Name %02d', $sequence->index + 1),
        ])->create();
    Employee::factory()->inactive()->forCompany($company)->create([
        'external_id' => 'INACTIVE-EMPLOYEE',
        'last_name' => 'A',
    ]);
    Employee::factory()->forCompany($otherCompany)->create(['external_id' => 'OTHER-COMPANY']);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->call('setPage', 2)
        ->set('search', 'EMP-01')
        ->assertSee('EMP-01');

    Livewire::test(Index::class)
        ->call('setPage', 2)
        ->set('filter', 'all')
        ->assertSee('INACTIVE-EMPLOYEE')
        ->assertDontSee('OTHER-COMPANY');
});
