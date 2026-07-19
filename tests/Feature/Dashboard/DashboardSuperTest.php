<?php

use App\Livewire\Dashboard\SuperAdmin;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LoginAttempt;
use App\Models\PayPeriod;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('guest is redirected from super dashboard', function () {
    $this->get('/dashboard/super')->assertRedirect('/login');
});

test('company admin cannot access super dashboard', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('company_admin');

    $this->actingAs($user)
        ->get('/dashboard/super')
        ->assertStatus(403);
});

test('super admin sees global stats', function () {
    $companyA = Company::factory()->create(['is_active' => true]);
    $companyB = Company::factory()->create(['is_active' => false]);

    User::factory()->count(3)->create(['company_id' => $companyA->id, 'is_active' => true]);
    User::factory()->count(2)->create(['company_id' => $companyB->id, 'is_active' => false]);

    Employee::factory()->count(4)->forCompany($companyA)->create(['is_active' => true]);
    Employee::factory()->count(5)->forCompany($companyB)->create(['is_active' => true]);

    PayPeriod::factory()->forCompany($companyA)->create(['status' => 'processed']);
    PayPeriod::factory()->forCompany($companyA)->create(['status' => 'approved']);
    PayPeriod::factory()->forCompany($companyB)->create(['status' => 'draft']);
    PayPeriod::factory()->forCompany($companyB)->create(['status' => 'validation_failed']);

    $super = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(SuperAdmin::class)
        ->assertSet('activeCompanies', 1)
        ->assertSet('inactiveCompanies', 1)
        ->assertSet('activeUsers', 4)
        ->assertSet('activeEmployees', 9)
        ->assertSet('processedPayrolls', 2)
        ->assertSet('pendingPayrolls', 1)
        ->assertSet('errorPayrolls', 1);
});

test('super admin dashboard uses the global company context', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Employee::factory()->count(2)->forCompany($companyA)->create(['is_active' => true]);
    Employee::factory()->count(5)->forCompany($companyB)->create(['is_active' => true]);

    PayPeriod::factory()->forCompany($companyA)->create(['status' => 'processed']);
    PayPeriod::factory()->forCompany($companyB)->create(['status' => 'processed']);

    $super = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $super->assignRole('super_admin');
    $csrfToken = 'dashboard-company-test-token';

    $this->withSession(['_token' => $csrfToken])
        ->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => $companyA->slug,
        ])
        ->assertRedirect(route('dashboard'));

    Livewire::test(SuperAdmin::class)
        ->assertSet('activeCompanies', 1)
        ->assertSet('inactiveCompanies', 0)
        ->assertSet('activeEmployees', 2)
        ->assertSet('processedPayrolls', 1)
        ->assertSet('pendingPayrolls', 0);
});

test('super admin can filter by date range', function () {
    $company = Company::factory()->create();

    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(5),
        'status' => 'processed',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => now()->subDays(40),
        'end_date' => now()->subDays(35),
        'status' => 'processed',
    ]);

    $super = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(SuperAdmin::class)
        ->set('from', now()->subDays(15)->format('Y-m-d'))
        ->set('to', now()->format('Y-m-d'))
        ->assertSet('processedPayrolls', 1);
});

test('dashboard redirects super admin to super dashboard', function () {
    $super = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $super->assignRole('super_admin');

    $this->actingAs($super);

    $this->get('/dashboard')->assertRedirect('/dashboard/super');
});

test('recent activity table is contained in a named keyboard scroll region', function () {
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    LoginAttempt::create([
        'user_id' => $super->id,
        'email' => $super->email,
        'ip' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'success' => true,
    ]);

    $response = $this->actingAs($super)->get(route('dashboard.super'));

    $response->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $tables = (new DOMXPath($document))->query(
        '//*[@role="region" and @aria-labelledby="recent-activity-heading" and @tabindex="0"'
        .' and contains(concat(" ", normalize-space(@class), " "), " overflow-x-auto ")]//table',
    );

    expect($tables->length)->toBe(1);
});
