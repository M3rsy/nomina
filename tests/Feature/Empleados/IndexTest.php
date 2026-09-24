<?php

use App\Livewire\Empleados\Delete;
use App\Livewire\Empleados\Index;
use App\Livewire\Empleados\ToggleActivate;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    /** @var \Tests\TestCase $this */
    $this->seed(PermissionRoleSeeder::class);
});

test('super admin paginates all company employees with stable ordering', function () {
    /** @var \Tests\TestCase $this */
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
    /** @var \Tests\TestCase $this */
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

test('employees can be filtered to inactive records and filters can be cleared', function () {
    /** @var \Tests\TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    Employee::factory()->forCompany($company)->create(['external_id' => 'CURRENT-RECORD']);
    Employee::factory()->inactive()->forCompany($company)->create(['external_id' => 'DORMANT-RECORD']);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->set('filter', 'inactive')
        ->set('search', 'DORMANT')
        ->assertSee('DORMANT-RECORD')
        ->assertDontSee('CURRENT-RECORD')
        ->assertSee('1 resultado')
        ->call('clearFilters')
        ->assertSet('filter', 'active')
        ->assertSet('search', '')
        ->assertSee('CURRENT-RECORD')
        ->assertDontSee('DORMANT-RECORD');
});

test('employee list refreshes after nested status and delete actions', function () {
    /** @var \Tests\TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create(['external_id' => 'REFRESH-ME']);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(ToggleActivate::class, ['employee' => $employee])
        ->call('toggle')
        ->assertDispatched('employee-status-changed');

    expect($employee->fresh()->is_active)->toBeFalse();

    $employee->update(['is_active' => true]);

    Livewire::test(Delete::class, ['employee' => $employee->fresh()])
        ->call('destroy')
        ->assertDispatched('employee-deleted');

    expect($employee->fresh()->trashed())->toBeTrue();
});

test('employee directory presents the Stitch-inspired hierarchy with real result data', function () {
    /** @var \Tests\TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    Employee::factory()->forCompany($company)->create([
        'first_name' => 'Ana',
        'last_name' => 'López',
        'expected_salary' => 1250.50,
    ]);
    Employee::factory()->forCompany($company)->create();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->assertSee('Directorio oficial')
        ->assertSee('Resultados totales')
        ->assertSee('En esta página')
        ->assertSee('Búsqueda inactiva')
        ->assertSee('2 resultados')
        ->assertSee('2 visibles')
        ->assertSee('AL')
        ->assertSee('1,250.50')
        ->assertSeeHtml('data-employee-section="hero"')
        ->assertSeeHtml('data-employee-section="metrics"')
        ->assertSeeHtml('data-employee-section="filters"')
        ->assertSeeHtml('data-employee-section="directory"')
        ->assertSeeHtml('data-employee-avatar')
        ->assertSeeHtml('border-border');
});

test('employee row actions stay together in one ordered action bar', function () {
    /** @var \Tests\TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    Employee::factory()->forCompany($company)->create();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $html = Livewire::test(Index::class)
        ->assertSeeInOrder(['Editar', 'Desactivar', 'Eliminar'])
        ->html();

    preg_match('/<div\b[^>]*data-employee-actions[^>]*>/', $html, $actionBar);

    expect($actionBar)->toHaveCount(1)
        ->and($actionBar[0])->toContain('flex-nowrap')
        ->and($actionBar[0])->not->toContain('flex-wrap');
});
