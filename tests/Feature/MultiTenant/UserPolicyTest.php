<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;

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
