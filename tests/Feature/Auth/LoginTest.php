<?php

use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('login success redirects super admin to empresas', function () {
    $user = User::factory()->create([
        'email' => 'admin@nomina.test',
        'password' => Hash::make('password'),
        'company_id' => null,
    ]);
    $user->assignRole('super_admin');

    Livewire::test(\App\Livewire\Auth\Login::class)
        ->set('email', 'admin@nomina.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/empresas');

    $this->assertAuthenticatedAs($user);
});

test('login success redirects company admin to usuarios', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'email' => 'admin@empresa.test',
        'password' => Hash::make('password'),
        'company_id' => $company->id,
    ]);
    $user->assignRole('company_admin');

    Livewire::test(\App\Livewire\Auth\Login::class)
        ->set('email', 'admin@empresa.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/usuarios');

    $this->assertAuthenticatedAs($user);
});

test('login invalid credentials logs attempt and throws', function () {
    Livewire::test(\App\Livewire\Auth\Login::class)
        ->set('email', 'noexiste@nomina.test')
        ->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseHas('login_attempts', [
        'email' => 'noexiste@nomina.test',
        'success' => false,
        'user_id' => null,
    ]);
});

test('rate limiter blocks after 5 failed attempts', function () {
    $email = 'bloqueado@nomina.test';
    $key = strtolower($email).'|127.0.0.1';
    RateLimiter::clear($key);

    foreach (range(1, 5) as $i) {
        Livewire::test(\App\Livewire\Auth\Login::class)
            ->set('email', $email)
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors('email');
    }

    Livewire::test(\App\Livewire\Auth\Login::class)
        ->set('email', $email)
        ->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors('email');

    RateLimiter::clear($key);
});

test('disabled user cannot login and gets error', function () {
    $user = User::factory()->inactive()->create([
        'email' => 'disabled@nomina.test',
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('company_admin');

    Livewire::test(\App\Livewire\Auth\Login::class)
        ->set('email', 'disabled@nomina.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

test('login attempt recorded on success and failure', function () {
    $user = User::factory()->create([
        'email' => 'ok@nomina.test',
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('company_admin');

    Livewire::test(\App\Livewire\Auth\Login::class)
        ->set('email', 'ok@nomina.test')
        ->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors('email');

    Livewire::test(\App\Livewire\Auth\Login::class)
        ->set('email', 'ok@nomina.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/usuarios');

    $this->assertEquals(2, LoginAttempt::where('email', 'ok@nomina.test')->count());
    $this->assertEquals(1, LoginAttempt::where('email', 'ok@nomina.test')->where('success', true)->count());
});

test('logout clears session', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('super_admin');

    $this->actingAs($user);

    Livewire::test(\App\Livewire\Auth\Logout::class)
        ->call('logout')
        ->assertRedirect('/login');

    $this->assertGuest();
});
