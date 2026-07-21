<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('fase 8 routes are reachable for authorized users', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);

    $this->get('/dashboard')->assertRedirect('/dashboard/company');
    $this->get('/dashboard/company')->assertOk();
    $this->get('/auditoria')->assertOk();
    $this->get('/respaldos')->assertForbidden();
});

test('nav shows new links for authorized users', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $response = $this->actingAs($admin)->get('/dashboard/company');

    $response->assertSee('Panel');
    $response->assertSee('Auditoría');
    $response->assertDontSee('Respaldos');
});
