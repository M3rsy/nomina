<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Pest\TestSuite;
use Tests\TestCase;

uses()->beforeEach(function () {
    usersPresentationTestCase()->seed(PermissionRoleSeeder::class);
});

function usersPresentationTestCase(): TestCase
{
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('The current Pest test case is unavailable.');
    }

    return $test;
}

function usersPresentationSuperAdmin(): User
{
    return User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ])->assignRole('super_admin');
}

test('users index presents the Stitch-inspired access control workspace with real data only', function () {
    $company = Company::factory()->create(['name' => 'Ullrich Ltd']);
    $superAdmin = usersPresentationSuperAdmin();
    $companyAdmin = User::factory()->forCompany($company)->create([
        'name' => 'Gian Carlo Holmes',
        'email' => 'gian@example.test',
        'is_active' => true,
    ])->assignRole('company_admin');
    User::factory()->forCompany($company)->create([
        'name' => 'Usuario Inactivo',
        'email' => 'inactive@example.test',
        'is_active' => false,
    ])->assignRole('company_admin');

    $response = usersPresentationTestCase()->actingAs($superAdmin)->get(route('usuarios.index'));

    $response->assertOk();
    $response->assertSeeHtml('data-users-index="workspace"');
    $response->assertSee('Usuarios del Sistema');
    $response->assertSee('Control de Acceso y Permisos Multi-Empresa');
    $response->assertSee('Usuarios registrados');
    $response->assertSee('Usuarios visibles');
    $response->assertSee('Activos visibles');
    $response->assertSee('Roles visibles');
    $response->assertSee($companyAdmin->name);
    $response->assertSee($companyAdmin->email);
    $response->assertSee('Ullrich Ltd');
    $response->assertSee('company_admin');
    $response->assertSeeHtml('wire:model.live="search"');
    $response->assertSeeHtml('href="'.route('usuarios.create').'"');
    $response->assertSeeHtml('href="'.route('usuarios.edit', $companyAdmin).'"');
    $response->assertDontSee('MFA Activo');
    $response->assertDontSee('Invitar usuario');
    $response->assertDontSee('Nómina Specialist');
    $response->assertDontSee('Auditor Externo');
    $response->assertDontSee('SOX');
    $response->assertDontSee('Último inicio de sesión');
});

test('user create page presents the access setup workflow while preserving Livewire bindings', function () {
    $company = Company::factory()->create(['name' => 'Ullrich Ltd']);
    $superAdmin = usersPresentationSuperAdmin();
    session(['active_company_id' => $company->id]);

    $response = usersPresentationTestCase()->actingAs($superAdmin)->get(route('usuarios.create'));

    $response->assertOk();
    $response->assertSeeHtml('data-users-form="create"');
    $response->assertSee('Crear Nuevo Usuario');
    $response->assertSee('Datos de identidad y acceso');
    $response->assertSee('Rol y empresa asignada');
    $response->assertSee('Contraseña inicial');
    $response->assertSee('Ullrich Ltd');
    $response->assertSeeHtml('wire:submit="save"');
    $response->assertSeeHtml('wire:model="name"');
    $response->assertSeeHtml('wire:model="email"');
    $response->assertSeeHtml('wire:model="password"');
    $response->assertSeeHtml('wire:model="role"');
    $response->assertSeeHtml('wire:model="company_id"');
    $response->assertSeeHtml('wire:target="save"');
    $response->assertDontSee('Invitación Digital');
    $response->assertDontSee('SSO Corporativo');
    $response->assertDontSee('MFA');
    $response->assertDontSee('SOX');
    $response->assertDontSee('Borrador');
});

test('user edit page presents account recovery controls while preserving Livewire bindings', function () {
    $company = Company::factory()->create(['name' => 'Ullrich Ltd']);
    $superAdmin = usersPresentationSuperAdmin();
    $target = User::factory()->forCompany($company)->create([
        'name' => 'Administrador Empresa',
        'email' => 'admin.empresa@example.test',
        'is_active' => false,
    ])->assignRole('company_admin');

    $response = usersPresentationTestCase()->actingAs($superAdmin)->get(route('usuarios.edit', $target));

    $response->assertOk();
    $response->assertSeeHtml('data-users-form="edit"');
    $response->assertSee('Editar Usuario');
    $response->assertSee('Estado y recuperación de cuenta');
    $response->assertSee('Nueva contraseña opcional');
    $response->assertSee('Administrador Empresa');
    $response->assertSee('admin.empresa@example.test');
    $response->assertSeeHtml('wire:submit="save"');
    $response->assertSeeHtml('wire:model="name"');
    $response->assertSeeHtml('wire:model="email"');
    $response->assertSeeHtml('wire:model="password"');
    $response->assertSeeHtml('wire:model="role"');
    $response->assertSeeHtml('wire:model="company_id"');
    $response->assertSeeHtml('wire:model="is_active"');
    $response->assertSeeHtml('wire:target="save"');
    $response->assertDontSee('Reenviar invitación');
    $response->assertDontSee('MFA Activo');
    $response->assertDontSee('Auditar');
    $response->assertDontSee('SOX');
});
