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

test('company admin can view uploaded file detail with counts', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'original_name' => 'GLG_001.TXT',
        'status' => 'valid_with_warnings',
        'validation_summary' => [
            'total' => 34,
            'valid' => 32,
            'duplicate' => 1,
            'out_of_period' => 1,
            'unknown_employee' => 0,
            'invalid_row' => 0,
        ],
    ]);

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = $this->get('/archivos/'.$uploadedFile->id);
    $response->assertOk();
    $response->assertSee('GLG_001.TXT');
    $response->assertSee('Válido con advertencias');
    $response->assertSee('34');
    $response->assertSee('32');
});

test('company admin cannot view file detail from other company', function () {
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

    $response = $this->get('/archivos/'.$uploadedFile->id);
    $this->assertTrue(in_array($response->getStatusCode(), [403, 404], true));
});
