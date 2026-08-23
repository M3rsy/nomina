<?php

use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('guest is redirected from root to login', function () {
    $this->get('/')
        ->assertRedirect(route('login'));
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
        ->assertStatus(302)
        ->assertRedirect(route('login'));
});

test('authenticated non-admin user is redirected to dashboard from root', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertStatus(302)
        ->assertRedirect(route('dashboard'));
});

test('authenticated non-admin users follow a stable dashboard entry path', function () {
    $user = User::factory()->create();

    $rootResponse = $this->actingAs($user)->get('/');
    $rootResponse->assertStatus(302);
    $rootResponse->assertRedirect(route('dashboard'));

    $dashboardResponse = $this->actingAs($user)->get(route('dashboard'));
    $dashboardResponse->assertStatus(302);
    $dashboardResponse->assertRedirect(route('login'));

    $this->assertSame(route('dashboard'), $rootResponse->headers->get('Location'));
    $this->assertSame(route('login'), $dashboardResponse->headers->get('Location'));
});

test('login is a complete asset-backed page with a size-safe brand mark', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<!DOCTYPE html>', escape: false)
        ->assertSee('<link rel="stylesheet"', escape: false)
        ->assertSee('<script type="module"', escape: false)
        ->assertSee('data-auth-shell', escape: false)
        ->assertSee('<svg class="size-6" width="24" height="24"', escape: false);
});

test('guest accessing dashboard is redirected to login', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
