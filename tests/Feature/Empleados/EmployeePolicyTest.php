<?php

use App\Livewire\Empleados\Index;
use App\Livewire\Empleados\Restore;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('employee without permission cannot access', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    Employee::factory()->count(2)->forCompany($company)->create();

    $user = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);
    $response = $this->get('/empleados');

    $response->assertStatus(403);
});

test('another company admin cannot destroy our employee', function () {
    /** @var TestCase $this */
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $companyB->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $employee = Employee::factory()->forCompany($companyA)->create();

    $this->actingAs($admin);
    $response = $this->delete('/empleados/'.$employee->id);

    $this->assertTrue(in_array($response->getStatusCode(), [403, 404], true));
});

test('employee restore requires employee deletion permission', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $user = User::factory()->forCompany($company)->create();
    $user->givePermissionTo('employees.view');
    $employee = Employee::factory()->forCompany($company)->create();
    $employee->delete();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('filter', 'retired')
        ->assertSee($employee->external_id)
        ->assertDontSee('Restaurar');

    Livewire::test(Restore::class, ['employee' => $employee])
        ->assertForbidden();
});

test('company admin cannot restore a retired employee from another company', function () {
    /** @var TestCase $this */
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $admin = User::factory()->forCompany($companyB)->create()->assignRole('company_admin');
    $ownEmployee = Employee::factory()->forCompany($companyB)->create();
    $foreignEmployee = Employee::factory()->forCompany($companyA)->create();
    $ownEmployee->delete();
    $foreignEmployee->delete();

    $this->actingAs($admin);

    Livewire::test(Restore::class, ['employee' => $ownEmployee])
        ->set('employeeId', $foreignEmployee->id)
        ->call('restore')
        ->assertForbidden();

    expect($foreignEmployee->fresh()->trashed())->toBeTrue();
});
