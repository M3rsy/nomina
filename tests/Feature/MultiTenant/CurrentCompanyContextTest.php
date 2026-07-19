<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('super admin selects the active company through the explicit action', function () {
    $company = Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'current-company-test-token';

    $this->withSession(['_token' => $csrfToken])
        ->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => $company->slug,
        ])
        ->assertRedirect('/dashboard')
        ->assertSessionHas('active_company_id', $company->id);
});

test('active company action requires a valid csrf token', function () {
    $currentCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $originalEnvironment = app()->environment();

    app()->detectEnvironment(fn () => 'local');

    try {
        $response = $this->withSession(['active_company_id' => $currentCompany->id])
            ->actingAs($super)
            ->post(route('current-company.update'), ['company' => $otherCompany->slug]);
    } finally {
        app()->detectEnvironment(fn () => $originalEnvironment);
    }

    $response
        ->assertStatus(419)
        ->assertSessionHas('active_company_id', $currentCompany->id);
});

test('super admin clears the active company context', function () {
    $company = Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'current-company-test-token';

    $this->withSession([
        '_token' => $csrfToken,
        'active_company_id' => $company->id,
    ])->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => '',
        ])
        ->assertRedirect('/dashboard')
        ->assertSessionMissing('active_company_id');
});

test('company admin cannot change the active company context', function () {
    $ownCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $admin = User::factory()->forCompany($ownCompany)->create();
    $admin->assignRole('company_admin');
    $csrfToken = 'current-company-test-token';

    $this->withSession(['_token' => $csrfToken])
        ->actingAs($admin)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => $otherCompany->slug,
        ])
        ->assertForbidden()
        ->assertSessionMissing('active_company_id');
});

test('unknown company slug does not replace the active context', function () {
    $currentCompany = Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'current-company-test-token';

    $this->withSession([
        '_token' => $csrfToken,
        'active_company_id' => $currentCompany->id,
    ])->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => 'empresa-inexistente',
        ])
        ->assertNotFound()
        ->assertSessionHas('active_company_id', $currentCompany->id);
});

test('inactive company does not replace the active context', function () {
    $currentCompany = Company::factory()->create();
    $inactiveCompany = Company::factory()->inactive()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'current-company-test-token';

    $this->withSession([
        '_token' => $csrfToken,
        'active_company_id' => $currentCompany->id,
    ])->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => $inactiveCompany->slug,
        ])
        ->assertNotFound()
        ->assertSessionHas('active_company_id', $currentCompany->id);
});

test('malformed company input is rejected without replacing the active context', function () {
    $currentCompany = Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'current-company-test-token';

    $this->withSession([
        '_token' => $csrfToken,
        'active_company_id' => $currentCompany->id,
    ])->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => ['invalid'],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('company')
        ->assertSessionHas('active_company_id', $currentCompany->id);
});

test('company edit route does not collide with active company selection', function () {
    $company = Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $this->actingAs($super)
        ->get(route('empresas.edit', $company))
        ->assertOk()
        ->assertSessionMissing('active_company_id');
});

test('missing active company is cleared from the session', function () {
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $this->withSession(['active_company_id' => 999999])
        ->actingAs($super)
        ->get(route('dashboard.super'))
        ->assertOk()
        ->assertSessionMissing('active_company_id');
});

test('inactive active company is cleared from the session', function () {
    $company = Company::factory()->inactive()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $this->withSession(['active_company_id' => $company->id])
        ->actingAs($super)
        ->get(route('dashboard.super'))
        ->assertOk()
        ->assertSessionMissing('active_company_id');
});
