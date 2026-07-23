<?php

use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('guest sees welcome page', function () {
    $this->get('/')
        ->assertOk()
        ->assertViewIs('welcome');
});

test('authenticated user with super_admin role is redirected to dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

test('authenticated user with company_admin role is redirected to dashboard', function () {
    $user = User::factory()->forCompany()->create();
    $user->assignRole('company_admin');

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

test('authenticated non-admin user is redirected to login from dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('authenticated non-admin user is redirected to dashboard from root', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

test('guest sees landing hero and login call-to-action', function () {
    $this->get('/')
        ->assertOk()
        ->assertSeeText('Gestioná asistencia y nómina desde un solo ingreso')
        ->assertSeeText('Iniciar sesión')
        ->assertSee(route('login'));
});

test('guest sees operational modules cards', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Asistencia y jornadas')
        ->assertSee('Nómina')
        ->assertSee('Feriados')
        ->assertSee('Respaldos')
        ->assertSee('Usuarios/Empresa')
        ->assertSee('Estado del sistema');
});

test('guest accessing dashboard is redirected to login', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
