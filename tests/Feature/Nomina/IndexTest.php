<?php

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('company admin can view nomina index of own company', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'uploaded']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    $this->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertSee($payPeriod->name)
        ->assertSee('/nomina/'.$payPeriod->id.'/revisar');
});

test('company admin cannot view nomina index of other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    PayPeriod::factory()->forCompany($companyB)->create();
    $admin = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($companyA);

    $this->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertDontSee('Empresa B');
});

test('super admin without active company sees empty nomina index', function () {
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    app(CurrentCompany::class)->set(null);

    $this->actingAs($superAdmin)
        ->get('/nomina')
        ->assertOk();
});

test('create period button shown when user can manage pay periods', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    $this->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertSee('Crear período');
});

test('user without pay periods view permission cannot access nomina index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/nomina')
        ->assertForbidden();
});
