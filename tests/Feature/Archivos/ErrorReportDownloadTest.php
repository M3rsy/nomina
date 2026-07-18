<?php

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('company admin can download csv error report', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($uploadedFile)->create([
        'employee_external_id' => '99999',
        'event_at' => '2026-01-19 14:53:50',
        'row_number' => 1,
        'status' => 'unknown_employee',
        'notes' => 'Empleado no encontrado',
    ]);

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($uploadedFile)->create([
        'employee_external_id' => '13767',
        'event_at' => '2026-02-01 08:00:00',
        'row_number' => 2,
        'status' => 'out_of_period',
        'notes' => 'Fuera del período',
    ]);

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($uploadedFile)->create([
        'employee_external_id' => '13767',
        'event_at' => '2026-01-19 14:53:50',
        'row_number' => 3,
        'status' => 'valid',
    ]);

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = $this->get('/archivos/'.$uploadedFile->id.'/reporte');
    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $content = $response->streamedContent();
    expect($content)->toContain('99999');
    expect($content)->toContain('unknown_employee');
    expect($content)->toContain('out_of_period');
    expect($content)->not->toContain('valid');
});

test('company admin cannot download report from other company file', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create();
    $uploadedFile = UploadedFile::factory()->forCompany($companyB)->forPayPeriod($payPeriodB)->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($companyA);

    $response = $this->get('/archivos/'.$uploadedFile->id.'/reporte');
    $this->assertTrue(in_array($response->getStatusCode(), [403, 404], true));
});
