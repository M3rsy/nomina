<?php

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('company admin lists only own company files', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodA = PayPeriod::factory()->forCompany($companyA)->create();
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create();

    UploadedFile::factory()->forCompany($companyA)->forPayPeriod($payPeriodA)->create(['original_name' => 'alpha.txt']);
    UploadedFile::factory()->forCompany($companyB)->forPayPeriod($payPeriodB)->create(['original_name' => 'beta.txt']);

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($companyA);

    $response = $this->get('/archivos');
    $response->assertOk();
    $response->assertSee('alpha.txt');
    $response->assertDontSee('beta.txt');
});

test('index filters by status', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();

    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'original_name' => 'valid.txt',
        'status' => 'valid',
    ]);
    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'original_name' => 'invalid.txt',
        'status' => 'invalid',
    ]);

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = $this->get('/archivos?status=valid');
    $response->assertOk();
    $response->assertSee('valid.txt');
    $response->assertDontSee('invalid.txt');
});

test('index filters by pay period', function () {
    $company = Company::factory()->create();
    $payPeriodA = PayPeriod::factory()->forCompany($company)->create();
    $payPeriodB = PayPeriod::factory()->forCompany($company)->create();

    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriodA)->create(['original_name' => 'a.txt']);
    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriodB)->create(['original_name' => 'b.txt']);

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = $this->get('/archivos?pay_period_id='.$payPeriodA->id);
    $response->assertOk();
    $response->assertSee('a.txt');
    $response->assertDontSee('b.txt');
});

test('index search filters by original name', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();

    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create(['original_name' => 'GLG_001.TXT']);
    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create(['original_name' => 'attlog.dat']);

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = $this->get('/archivos?search=GLG');
    $response->assertOk();
    $response->assertSee('GLG_001.TXT');
    $response->assertDontSee('attlog.dat');
});
