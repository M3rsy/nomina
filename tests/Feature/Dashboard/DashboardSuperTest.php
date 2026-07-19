<?php

use App\Livewire\Dashboard\SuperAdmin;
use App\Models\Company;
use App\Models\Employee;
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

test('super admin without an active company sees organization snapshots but no payroll aggregation', function () {
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
        ->assertSet('payrollOverview', [])
        ->assertSee('Resumen operativo de nómina')
        ->assertSee('Este resumen nunca combina empresas.')
        ->assertSee('Usá el selector de empresa de la barra superior.');
});

test('active company payroll overview counts every recognized and unknown status without cross-tenant data', function () {
    $companyA = Company::factory()->create(['name' => 'Empresa Operativa']);
    $companyB = Company::factory()->create(['name' => 'Empresa Externa']);

    Employee::factory()->count(2)->forCompany($companyA)->create(['is_active' => true]);
    Employee::factory()->count(5)->forCompany($companyB)->create(['is_active' => true]);

    $statuses = [
        'draft',
        'uploaded',
        'validating',
        'ready',
        'processing',
        'processed',
        'approved',
        'exported',
        'validation_failed',
        'cancelled',
        'legacy_status',
    ];

    foreach ($statuses as $status) {
        PayPeriod::factory()->forCompany($companyA)->create(['status' => $status]);
        PayPeriod::factory()->forCompany($companyB)->create(['status' => $status]);
    }

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
        ->assertSet('payrollOverview.company_name', 'Empresa Operativa')
        ->assertSet('payrollOverview.total', 11)
        ->assertSet('payrollOverview.preparation', 4)
        ->assertSet('payrollOverview.processing', 1)
        ->assertSet('payrollOverview.completed', 3)
        ->assertSet('payrollOverview.validation_failed', 1)
        ->assertSet('payrollOverview.cancelled', 1)
        ->assertSet('payrollOverview.unknown', 1)
        ->assertSee('Empresa Operativa')
        ->assertSee('Total')
        ->assertSee('En preparación')
        ->assertSee('Procesando')
        ->assertSee('Completadas')
        ->assertSee('Validación con errores')
        ->assertSee('Canceladas')
        ->assertSee('Otros estados registrados');
});

test('payroll overview date range includes only fully contained active-company periods', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-20',
        'status' => 'processing',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2025-12-31',
        'end_date' => '2026-01-20',
        'status' => 'processed',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-02-01',
        'status' => 'validation_failed',
    ]);
    PayPeriod::factory()->forCompany($otherCompany)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'cancelled',
    ]);

    $super = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $super->assignRole('super_admin');
    $this->actingAs($super);
    session(['active_company_id' => $company->id]);

    Livewire::test(SuperAdmin::class)
        ->set('from', '2026-01-01')
        ->set('to', '2026-01-31')
        ->assertSet('payrollOverview.total', 2)
        ->assertSet('payrollOverview.preparation', 1)
        ->assertSet('payrollOverview.processing', 1)
        ->assertSet('payrollOverview.completed', 0)
        ->assertSet('payrollOverview.validation_failed', 0)
        ->assertSet('payrollOverview.cancelled', 0)
        ->assertSet('payrollOverview.unknown', 0)
        ->assertDontSee('Otros estados registrados');
});

test('active company with no payroll periods sees an actionable empty state', function () {
    $company = Company::factory()->create(['name' => 'Empresa sin períodos']);
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $this->actingAs($super);
    session(['active_company_id' => $company->id]);

    Livewire::test(SuperAdmin::class)
        ->assertSet('payrollOverview.company_name', 'Empresa sin períodos')
        ->assertSet('payrollOverview.total', 0)
        ->assertSet('payrollOverview.has_periods', false)
        ->assertSee('Todavía no hay períodos de nómina para esta empresa.')
        ->assertSee('Ver períodos de nómina')
        ->assertSeeHtml('href="'.route('nomina.index').'"');
});

test('active company with no range matches distinguishes filtered results from no periods', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
        'status' => 'draft',
    ]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $this->actingAs($super);
    session(['active_company_id' => $company->id]);

    Livewire::test(SuperAdmin::class)
        ->set('from', '2026-01-01')
        ->set('to', '2026-01-31')
        ->assertSet('payrollOverview.total', 0)
        ->assertSet('payrollOverview.has_periods', true)
        ->assertSee('No hay períodos que coincidan con el rango seleccionado.')
        ->assertDontSee('Todavía no hay períodos de nómina para esta empresa.');
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

test('super dashboard omits recent activity and hands history off to audit', function () {
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(SuperAdmin::class)
        ->assertDontSee('Actividad reciente')
        ->assertDontSee('No hay actividad reciente.')
        ->assertDontSeeHtml('id="recent-activity-heading"')
        ->assertSee('Ver historial en Auditoría')
        ->assertSeeHtml('href="'.route('auditoria.index').'"');
});

test('super dashboard hides audit handoff without permission', function () {
    $company = Company::factory()->create();
    $user = User::factory()->forCompany($company)->create();
    $user->givePermissionTo('companies.view');

    Livewire::actingAs($user)
        ->test(SuperAdmin::class)
        ->assertDontSee('Ver historial en Auditoría')
        ->assertDontSeeHtml('href="'.route('auditoria.index').'"')
        ->assertDontSee('Ver períodos de nómina')
        ->assertDontSeeHtml('href="'.route('nomina.index').'"');
});
