<?php

use App\Livewire\Dashboard\CompanyAdmin;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\UploadedFile;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('guest is redirected from company dashboard', function () {
    $this->get('/dashboard/company')->assertRedirect('/login');
});

test('super admin cannot access company dashboard without company', function () {
    $super = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $super->assignRole('super_admin');

    $response = $this->actingAs($super)->get('/dashboard/company');

    $response->assertOk();
    $response->assertSee('No hay una empresa activa seleccionada');
});

test('company admin sees only own company stats', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $sharedEmployee = Employee::factory()->forCompany($companyA)->create(['is_active' => true]);
    Employee::factory()->forCompany($companyA)->create(['is_active' => true]);
    Employee::factory()->count(5)->forCompany($companyB)->create(['is_active' => true]);

    $payPeriodA = PayPeriod::factory()->forCompany($companyA)->create([
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(5),
        'status' => 'processed',
    ]);
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create([
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(5),
        'status' => 'processed',
    ]);

    PayrollResult::factory()
        ->forCompany($companyA)
        ->forPayPeriod($payPeriodA)
        ->forEmployee($sharedEmployee)
        ->count(3)
        ->create([
            'ordinary_hours' => 8,
            'extra_25_hours' => 1,
        ]);

    UploadedFile::factory()->forCompany($companyA)->forPayPeriod($payPeriodA)->count(2)->create();
    UploadedFile::factory()->forCompany($companyB)->forPayPeriod($payPeriodB)->count(4)->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    Livewire::actingAs($admin)
        ->test(CompanyAdmin::class)
        ->assertSet('activeEmployees', 2)
        ->assertSet('pendingPayrolls', 0)
        ->assertSet('errorPayrolls', 0)
        ->assertSet('payPeriods', function ($periods) {
            return count($periods) === 1 && $periods[0]['results_count'] === 3;
        })
        ->assertSet('recentFiles', function ($files) {
            return count($files) === 2;
        });
});

test('company dashboard derives payroll totals from canonical minutes', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'processed']);

    PayrollResult::factory()
        ->forCompany($company)
        ->forPayPeriod($payPeriod)
        ->forEmployee($employee)
        ->count(2)
        ->create([
            'worked_hours' => 0.03,
            'ordinary_hours' => 0.02,
            'extra_25_hours' => 0.02,
            'worked_minutes' => 2,
            'ordinary_minutes' => 1,
            'extra_25_minutes' => 1,
        ]);

    $admin = User::factory()->forCompany($company)->create();
    $admin->assignRole('company_admin');

    Livewire::actingAs($admin)
        ->test(CompanyAdmin::class)
        ->assertSet('payPeriods', function (array $periods): bool {
            return count($periods) === 1
                && abs($periods[0]['worked_hours'] - (4 / 60)) < 0.000001
                && abs($periods[0]['ordinary_hours'] - (2 / 60)) < 0.000001
                && abs($periods[0]['extra_hours'] - (2 / 60)) < 0.000001;
        });
});

test('company admin date filter excludes out of range payrolls', function () {
    $company = Company::factory()->create();

    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(5),
        'status' => 'draft',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => now()->subDays(40),
        'end_date' => now()->subDays(35),
        'status' => 'draft',
    ]);

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    Livewire::actingAs($admin)
        ->test(CompanyAdmin::class)
        ->set('from', now()->subDays(15)->format('Y-m-d'))
        ->set('to', now()->format('Y-m-d'))
        ->assertSet('pendingPayrolls', 1);
});

test('dashboard redirects company admin to company dashboard', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);

    $this->get('/dashboard')->assertRedirect('/dashboard/company');
});

test('payroll periods table is contained in a named keyboard scroll region', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create();
    $admin->assignRole('company_admin');

    $response = $this->actingAs($admin)->get(route('dashboard.company'));

    $response->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $tables = (new DOMXPath($document))->query(
        '//*[@role="region" and @aria-labelledby="payroll-periods-heading" and @tabindex="0"'
        .' and contains(concat(" ", normalize-space(@class), " "), " overflow-x-auto ")]//table',
    );

    expect($tables->length)->toBe(1);
});
