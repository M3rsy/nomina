<?php

use App\Livewire\Usuarios\Create;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('company admin can see only own company users', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    User::factory()->count(2)->forCompany($companyA)->create();
    User::factory()->count(3)->forCompany($companyB)->create();

    $this->actingAs($admin);
    $response = $this->get('/usuarios');

    $response->assertOk();
    $response->assertSee($companyA->users()->first()->email);
    $response->assertDontSee($companyB->users()->first()->email);
});

test('super admin can see all users', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('super_admin');

    User::factory()->count(2)->forCompany($companyA)->create();
    User::factory()->count(3)->forCompany($companyB)->create();

    $this->actingAs($admin);
    $response = $this->get('/usuarios');

    $response->assertOk();
    $response->assertSee($companyA->users()->first()->email);
    $response->assertSee($companyB->users()->first()->email);
});

test('super admin user index follows and clears the global company context', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userA = User::factory()->forCompany($companyA)->create(['email' => 'user-a@example.test']);
    $userB = User::factory()->forCompany($companyB)->create(['email' => 'user-b@example.test']);
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'users-company-test-token';

    $this->withSession(['_token' => $csrfToken])
        ->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => $companyA->slug,
        ])
        ->assertRedirect(route('dashboard'));

    $this->get(route('usuarios.index'))
        ->assertOk()
        ->assertSee($userA->email)
        ->assertDontSee($userB->email);

    $this->post(route('current-company.update'), [
        '_token' => $csrfToken,
        'company' => '',
    ])->assertRedirect(route('dashboard'));

    $this->get(route('usuarios.index'))
        ->assertOk()
        ->assertSee($userA->email)
        ->assertSee($userB->email);
});

test('new user defaults to the global company context', function () {
    $company = Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'users-company-test-token';

    $this->withSession(['_token' => $csrfToken])
        ->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => $company->slug,
        ]);

    Livewire::test(Create::class)
        ->assertSet('company_id', $company->id);
});
