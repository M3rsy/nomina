<?php

use App\Livewire\Archivos\Upload;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    Storage::fake('local');
});

test('company admin cannot upload file to other company pay period', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($companyA);

    $file = LaravelUploadedFile::fake()->createWithContent('GLG_001.TXT', "1\t1\t13767\t\t1\t1\t01/19/2026 14:53:50\r\n");

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriodB->id)
        ->set('upload', $file)
        ->call('store')
        ->assertHasErrors('pay_period_id');

    expect(UploadedFile::count())->toBe(0);
});

test('company admin cannot see upload link or other company file in index', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create();
    $uploadedFile = UploadedFile::factory()->forCompany($companyB)->forPayPeriod($payPeriodB)->create(['original_name' => 'other.txt']);

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($companyA);

    $response = $this->get('/archivos');
    $response->assertOk();
    $response->assertDontSee('other.txt');
    $response->assertSee('Subir archivo');
});

test('company admin cannot access other company file detail', function () {
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

test('super admin can access any company file detail', function () {
    $companyA = Company::factory()->create();
    $payPeriodA = PayPeriod::factory()->forCompany($companyA)->create();
    $uploadedFile = UploadedFile::factory()->forCompany($companyA)->forPayPeriod($payPeriodA)->create(['original_name' => 'super.txt']);

    $superAdmin = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set($companyA);

    $response = $this->get('/archivos/'.$uploadedFile->id);
    $response->assertOk();
    $response->assertSee('super.txt');
});
