<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('company admin cannot view empresas index', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('company_admin');

    $this->actingAs($user);
    $response = $this->get('/empresas');

    $response->assertStatus(403);
});

test('company admin cannot view users of other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $otherUser = User::factory()->create([
        'company_id' => $companyB->id,
    ]);

    $this->actingAs($admin);
    $response = $this->get('/usuarios/'.$otherUser->id.'/editar');

    $response->assertStatus(403);
});

test('company admin cannot create user in other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);

    Livewire::test(\App\Livewire\Usuarios\Create::class)
        ->set('name', 'Nuevo usuario')
        ->set('email', 'nuevo@empresa-b.test')
        ->set('password', 'password123')
        ->set('role', 'company_admin')
        ->set('company_id', $companyB->id)
        ->call('save')
        ->assertHasErrors('company_id');
});

test('company admin cannot assign super admin role', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);

    Livewire::test(\App\Livewire\Usuarios\Create::class)
        ->set('name', 'Nuevo usuario')
        ->set('email', 'nuevo@empresa.test')
        ->set('password', 'password123')
        ->set('role', 'super_admin')
        ->call('save')
        ->assertHasErrors('role');
});

test('super admin can view all companies and switch context', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('super_admin');

    $this->actingAs($admin);

    $response = $this->get('/empresas');
    $response->assertOk();

    $response = $this->get('/empresas?company='.$companyB->slug);
    $response->assertOk();

    expect(session('active_company_id'))->toBe($companyB->id);
});
