<?php

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('company admin has marks edit permission', function () {
    $admin = User::factory()->create()->assignRole('company_admin');

    expect($admin->hasPermissionTo('marks.edit'))->toBeTrue();
    expect($admin->hasPermissionTo('marks.manage'))->toBeTrue();
});

test('super admin has all marks permissions', function () {
    $superAdmin = User::factory()->create()->assignRole('super_admin');

    expect($superAdmin->hasPermissionTo('marks.edit'))->toBeTrue();
    expect($superAdmin->hasPermissionTo('marks.manage'))->toBeTrue();
});

test('company admin can manage raw marks of own company', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $rawMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create();

    expect($admin->can('manage', $rawMark))->toBeTrue();
    expect($admin->can('edit', $rawMark))->toBeTrue();
    expect($admin->can('delete', $rawMark))->toBeTrue();
    expect($admin->can('assign', $rawMark))->toBeTrue();
});

test('company admin cannot manage raw marks of other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $adminA = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create();
    $fileB = UploadedFile::factory()->forCompany($companyB)->forPayPeriod($payPeriodB)->create();
    $rawMarkB = RawMark::factory()->forCompany($companyB)->forPayPeriod($payPeriodB)->forUploadedFile($fileB)->create();

    expect($adminA->can('manage', $rawMarkB))->toBeFalse();
    expect($adminA->can('edit', $rawMarkB))->toBeFalse();
    expect($adminA->can('delete', $rawMarkB))->toBeFalse();
    expect($adminA->can('assign', $rawMarkB))->toBeFalse();
});

test('super admin can manage any raw mark', function () {
    $company = Company::factory()->create();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $rawMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create();

    expect($superAdmin->can('manage', $rawMark))->toBeTrue();
});
