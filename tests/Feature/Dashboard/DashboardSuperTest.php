<?php

use App\Livewire\Dashboard\SuperAdmin;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
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

    $companyAEmployees = Employee::factory()->count(4)->forCompany($companyA)->create(['is_active' => true]);
    Employee::factory()->count(5)->forCompany($companyB)->create(['is_active' => true]);

    $companyAPeriod = PayPeriod::factory()->forCompany($companyA)->create(['status' => 'processed']);
    PayPeriod::factory()->forCompany($companyA)->create(['status' => 'approved']);
    PayPeriod::factory()->forCompany($companyB)->create(['status' => 'draft']);
    PayPeriod::factory()->forCompany($companyB)->create(['status' => 'validation_failed']);
    PayrollResult::factory()
        ->forCompany($companyA)
        ->forPayPeriod($companyAPeriod)
        ->forEmployee($companyAEmployees->first())
        ->create(['date' => '2026-01-15', 'ordinary_hours' => 987.65]);

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
        ->assertSee('Usá el selector de empresa de la barra superior.')
        ->assertSee('Tendencia mensual de nómina')
        ->assertSee('Seleccioná una empresa activa para consultar su tendencia mensual de nómina.')
        ->assertDontSee('987.65');
});

test('monthly payroll trends aggregate exact active-company values in chronological order', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    PayrollResult::factory()->forCompany($company)->createMany([
        [
            'date' => '2026-01-05',
            'ordinary_hours' => 7.50,
            'extra_25_hours' => 1,
            'extra_50_hours' => 2,
            'extra_75_hours' => 3,
            'extra_100_hours' => 4,
        ],
        [
            'date' => '2026-01-31',
            'ordinary_hours' => 8.25,
            'extra_25_hours' => 2,
            'extra_75_hours' => 1,
        ],
        [
            'date' => '2026-02-01',
            'ordinary_hours' => 6.75,
            'extra_50_hours' => 1,
            'extra_100_hours' => 2,
        ],
    ]);
    PayrollResult::factory()->forCompany($otherCompany)->create([
        'date' => '2026-01-10',
        'ordinary_hours' => 999.99,
        'extra_100_hours' => 400,
    ]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $this->actingAs($super);
    session(['active_company_id' => $company->id]);

    Livewire::test(SuperAdmin::class)
        ->assertSeeInOrder([
            'Enero de 2026',
            'Registros de resultado',
            '2',
            'Horas ordinarias',
            '15.75',
            'Horas extras',
            '13.00',
            'Febrero de 2026',
            'Registros de resultado',
            '1',
            'Horas ordinarias',
            '6.75',
            'Horas extras',
            '3.00',
        ])
        ->assertSeeHtml('aria-hidden="true"')
        ->assertSeeHtml('style="width: 100%"')
        ->assertSeeHtml('style="width: 50%"')
        ->assertDontSee('999.99')
        ->assertDontSee('400.00');
});

test('monthly payroll trend date filters include both result-date boundaries', function () {
    $company = Company::factory()->create();
    PayrollResult::factory()->forCompany($company)->createMany([
        [
            'date' => '2026-03-01',
            'ordinary_hours' => 1.25,
            'extra_25_hours' => 1,
        ],
        [
            'date' => '2026-03-31',
            'ordinary_hours' => 2.50,
            'extra_50_hours' => 2,
        ],
        [
            'date' => '2026-02-28',
            'ordinary_hours' => 100,
            'extra_75_hours' => 100,
        ],
        [
            'date' => '2026-04-01',
            'ordinary_hours' => 200,
            'extra_100_hours' => 200,
        ],
    ]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $this->actingAs($super);
    session(['active_company_id' => $company->id]);

    Livewire::test(SuperAdmin::class)
        ->set('from', '2026-03-01')
        ->set('to', '2026-03-31')
        ->assertSee('La tendencia mensual usa la fecha de cada resultado con límites inclusivos.')
        ->assertSeeInOrder([
            'Marzo de 2026',
            'Registros de resultado',
            '2',
            'Horas ordinarias',
            '3.75',
            'Horas extras',
            '3.00',
        ])
        ->assertDontSee('100.00')
        ->assertDontSee('200.00');
});

test('monthly payroll trends render the same clear state for empty and filtered history', function () {
    $emptyCompany = Company::factory()->create();
    $filteredCompany = Company::factory()->create();
    PayrollResult::factory()->forCompany($filteredCompany)->create([
        'date' => '2026-01-15',
        'ordinary_hours' => 44.44,
    ]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $this->actingAs($super);

    session(['active_company_id' => $emptyCompany->id]);
    Livewire::test(SuperAdmin::class)
        ->assertSee('No hay resultados de nómina para la empresa activa en el rango de fechas actual.');

    session(['active_company_id' => $filteredCompany->id]);
    Livewire::test(SuperAdmin::class)
        ->set('from', '2026-02-01')
        ->set('to', '2026-02-28')
        ->assertSee('No hay resultados de nómina para la empresa activa en el rango de fechas actual.')
        ->assertDontSee('44.44');
});

test('sparse one-month payroll history exposes stable semantic exact values', function () {
    $company = Company::factory()->create();
    PayrollResult::factory()->forCompany($company)->create([
        'date' => '2026-05-20',
        'ordinary_hours' => 7.25,
        'extra_25_hours' => 1,
        'extra_50_hours' => 2,
        'extra_75_hours' => 3,
        'extra_100_hours' => 4,
    ]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $this->actingAs($super);
    session(['active_company_id' => $company->id]);

    Livewire::test(SuperAdmin::class)
        ->assertSeeHtml('aria-labelledby="payroll-trends-heading"')
        ->assertSeeHtml('<ol')
        ->assertSeeHtml('<dl')
        ->assertSeeHtml('datetime="2026-05"')
        ->assertSeeInOrder([
            'Mayo de 2026',
            'Registros de resultado',
            '1',
            'Horas ordinarias',
            '7.25',
            'Horas extras',
            '10.00',
        ])
        ->assertSeeHtml('aria-hidden="true"')
        ->assertSeeHtml('style="width: 100%"')
        ->assertDontSee('No hay resultados de nómina para la empresa activa en el rango de fechas actual.');
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
