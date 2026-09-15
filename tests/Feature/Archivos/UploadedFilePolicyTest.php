<?php

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function uploadedFileForCompany(Company $company): UploadedFile
{
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();

    return UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
}

test('super admin cannot access uploaded files without an active company context', function () {
    $company = Company::factory()->create();
    $uploadedFile = uploadedFileForCompany($company);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set(null);

    expect($superAdmin->can('viewAny', UploadedFile::class))->toBeFalse()
        ->and($superAdmin->can('create', UploadedFile::class))->toBeFalse()
        ->and($superAdmin->can('view', $uploadedFile))->toBeFalse()
        ->and($superAdmin->can('manage', $uploadedFile))->toBeFalse()
        ->and($superAdmin->can('delete', $uploadedFile))->toBeFalse();
});

test('uploaded file instance abilities require the active company to match the file company', function () {
    $activeCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $activeFile = uploadedFileForCompany($activeCompany);
    $otherFile = uploadedFileForCompany($otherCompany);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set($activeCompany);

    expect($superAdmin->can('viewAny', UploadedFile::class))->toBeTrue()
        ->and($superAdmin->can('create', UploadedFile::class))->toBeTrue()
        ->and($superAdmin->can('view', $activeFile))->toBeTrue()
        ->and($superAdmin->can('manage', $activeFile))->toBeTrue()
        ->and($superAdmin->can('delete', $activeFile))->toBeTrue()
        ->and($superAdmin->can('view', $otherFile))->toBeFalse()
        ->and($superAdmin->can('manage', $otherFile))->toBeFalse()
        ->and($superAdmin->can('delete', $otherFile))->toBeFalse();
});

test('uploaded file abilities reject inactive current company context', function () {
    $inactiveCompany = Company::factory()->inactive()->create();
    $uploadedFile = uploadedFileForCompany($inactiveCompany);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set($inactiveCompany);

    expect($superAdmin->can('viewAny', UploadedFile::class))->toBeFalse()
        ->and($superAdmin->can('create', UploadedFile::class))->toBeFalse()
        ->and($superAdmin->can('view', $uploadedFile))->toBeFalse()
        ->and($superAdmin->can('manage', $uploadedFile))->toBeFalse()
        ->and($superAdmin->can('delete', $uploadedFile))->toBeFalse();
});

test('company admin cannot borrow another active company context', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $otherFile = uploadedFileForCompany($otherCompany);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($otherCompany);

    expect($admin->can('viewAny', UploadedFile::class))->toBeFalse()
        ->and($admin->can('create', UploadedFile::class))->toBeFalse()
        ->and($admin->can('view', $otherFile))->toBeFalse()
        ->and($admin->can('manage', $otherFile))->toBeFalse()
        ->and($admin->can('delete', $otherFile))->toBeFalse();
});

test('company admin keeps uploaded file abilities for the active company', function () {
    $company = Company::factory()->create();
    $uploadedFile = uploadedFileForCompany($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    expect($admin->can('viewAny', UploadedFile::class))->toBeTrue()
        ->and($admin->can('create', UploadedFile::class))->toBeTrue()
        ->and($admin->can('view', $uploadedFile))->toBeTrue()
        ->and($admin->can('manage', $uploadedFile))->toBeTrue()
        ->and($admin->can('delete', $uploadedFile))->toBeTrue();
});
