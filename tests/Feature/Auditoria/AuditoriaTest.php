<?php

use App\Livewire\Auditoria\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRevision;
use App\Models\LoginAttempt;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('guest is redirected from auditoria', function () {
    $this->get('/auditoria')->assertRedirect('/login');
});

test('user without audit.view permission cannot access auditoria', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->actingAs($user)
        ->get('/auditoria')
        ->assertStatus(403);
});

test('super admin sees all audit entries across companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->create(['company_id' => $companyA->id]);
    $adminB = User::factory()->create(['company_id' => $companyB->id]);

    LoginAttempt::factory()->create(['company_id' => $companyA->id, 'email' => $adminA->email, 'success' => true]);
    LoginAttempt::factory()->create(['company_id' => $companyB->id, 'email' => $adminB->email, 'success' => true]);

    $employeeA = Employee::factory()->forCompany($companyA)->create();
    EmployeeRevision::factory()->create(['employee_id' => $employeeA->id, 'user_id' => $adminA->id]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(Index::class)
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 3;
        });
});

test('company admin sees only own company audit entries', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->create(['company_id' => $companyA->id]);
    $adminB = User::factory()->create(['company_id' => $companyB->id]);

    LoginAttempt::factory()->create(['company_id' => $companyA->id, 'email' => $adminA->email, 'success' => true]);
    LoginAttempt::factory()->create(['company_id' => $companyB->id, 'email' => $adminB->email, 'success' => true]);

    $adminA->assignRole('company_admin');

    Livewire::actingAs($adminA)
        ->test(Index::class)
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('company admin without a company sees no tenant audit entries', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    LoginAttempt::factory()->create(['company_id' => $companyA->id]);
    LoginAttempt::factory()->create(['company_id' => $companyB->id]);

    $admin = User::factory()->create(['company_id' => null]);
    $admin->assignRole('company_admin');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 0);
});

test('super admin audit feed uses the global company context', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->create(['company_id' => $companyA->id]);
    $adminB = User::factory()->create(['company_id' => $companyB->id]);

    LoginAttempt::factory()->create(['company_id' => $companyA->id, 'email' => $adminA->email, 'success' => true]);
    LoginAttempt::factory()->create(['company_id' => $companyB->id, 'email' => $adminB->email, 'success' => true]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'audit-company-test-token';

    $this->withSession(['_token' => $csrfToken])
        ->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => $companyA->slug,
        ])
        ->assertRedirect(route('dashboard'));

    $this->get(route('auditoria.index'))
        ->assertOk()
        ->assertSee($adminA->email)
        ->assertDontSee($adminB->email);
});

test('type filter works', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    LoginAttempt::factory()->create(['company_id' => $company->id, 'email' => $admin->email, 'success' => true]);

    $employee = Employee::factory()->forCompany($company)->create();
    EmployeeRevision::factory()->create(['employee_id' => $employee->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('type', 'login_attempt')
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('date filter works', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    LoginAttempt::factory()->create([
        'company_id' => $company->id,
        'email' => $admin->email,
        'success' => true,
        'created_at' => now()->subDays(2),
    ]);
    LoginAttempt::factory()->create([
        'company_id' => $company->id,
        'email' => $admin->email,
        'success' => true,
        'created_at' => now()->subDays(30),
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('from', now()->subDays(10)->format('Y-m-d'))
        ->set('to', now()->format('Y-m-d'))
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('user filter works', function () {
    $company = Company::factory()->create();
    $adminA = User::factory()->create(['company_id' => $company->id, 'email' => 'admin_a@example.com']);
    $adminB = User::factory()->create(['company_id' => $company->id, 'email' => 'other@example.com']);
    $adminA->assignRole('company_admin');

    LoginAttempt::factory()->create(['company_id' => $company->id, 'email' => $adminA->email, 'success' => true]);
    LoginAttempt::factory()->create(['company_id' => $company->id, 'email' => $adminB->email, 'success' => true]);

    Livewire::actingAs($adminA)
        ->test(Index::class)
        ->set('user', 'admin_a')
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('pagination returns 25 items per page', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    LoginAttempt::factory()->count(30)->create([
        'company_id' => $company->id,
        'email' => $admin->email,
        'success' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertViewHas('entries', function ($entries) {
            return $entries->count() === 25 && $entries->total() === 30;
        });
});

test('raw mark revisions appear in audit feed', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    $rawMark = RawMark::factory()->forCompany($company)->create([
        'metadata' => [
            'revisions' => [
                [
                    'action' => 'edit_event_at',
                    'user_id' => $admin->id,
                    'at' => now()->toDateTimeString(),
                ],
            ],
        ],
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('type', 'mark_revision')
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('payroll state transitions appear in audit feed', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    PayPeriod::factory()->forCompany($company)->create([
        'status' => 'approved',
        'metadata' => [
            'approved_at' => now()->toDateTimeString(),
            'approved_by' => $admin->id,
        ],
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('type', 'payroll_state')
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});
