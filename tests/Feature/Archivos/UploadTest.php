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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    Storage::fake('local');
});

function actingAsCompanyAdmin(Company $company): User
{
    $user = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('company_admin');

    return $user;
}

test('company admin can upload glg file and records are parsed', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    Employee::factory()->forCompany($company)->create(['external_id' => '1222']);
    Employee::factory()->forCompany($company)->create(['external_id' => '12884']);
    Employee::factory()->forCompany($company)->create(['external_id' => '44']);

    $admin = actingAsCompanyAdmin($company);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $contents = file_get_contents('/home/m3rsy/GIt/proyecto-planilla/GLG_001 (1).TXT');
    $file = LaravelUploadedFile::fake()->createWithContent('GLG_001.TXT', $contents);

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriod->id)
        ->set('upload', $file)
        ->call('store')
        ->assertHasNoErrors();

    $uploadedFile = UploadedFile::first();
    expect($uploadedFile)->not->toBeNull();
    expect($uploadedFile->company_id)->toBe($company->id);
    expect($uploadedFile->pay_period_id)->toBe($payPeriod->id);
    expect($uploadedFile->sha256)->toBe(hash('sha256', $contents));
    expect($uploadedFile->extension)->toBe('txt');
    expect($uploadedFile->rawMarks()->count())->toBe(34);
});

test('company admin can upload attlog file and records are parsed', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    Employee::factory()->forCompany($company)->create(['external_id' => '12884']);
    Employee::factory()->forCompany($company)->create(['external_id' => '44']);
    Employee::factory()->forCompany($company)->create(['external_id' => '6419']);

    $admin = actingAsCompanyAdmin($company);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $contents = file_get_contents('/home/m3rsy/GIt/proyecto-planilla/A8ME233160030_attlog (1) (1).dat');
    $file = LaravelUploadedFile::fake()->createWithContent('attlog.dat', $contents);

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriod->id)
        ->set('upload', $file)
        ->call('store')
        ->assertHasNoErrors();

    $uploadedFile = UploadedFile::first();
    expect($uploadedFile)->not->toBeNull();
    expect($uploadedFile->rawMarks()->count())->toBe(38);
});

test('duplicate file by sha256 is rejected', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $admin = actingAsCompanyAdmin($company);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $contents = "1\t1\t13767\t\t1\t1\t01/19/2026 14:53:50\r\n";
    $file = LaravelUploadedFile::fake()->createWithContent('GLG_001.TXT', $contents);

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriod->id)
        ->set('upload', $file)
        ->call('store')
        ->assertHasNoErrors();

    $secondFile = LaravelUploadedFile::fake()->createWithContent('GLG_002.TXT', $contents);

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriod->id)
        ->set('upload', $secondFile)
        ->call('store')
        ->assertHasErrors('upload');

    expect(UploadedFile::count())->toBe(1);
});

test('upload validates file extension and size', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $admin = actingAsCompanyAdmin($company);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $file = LaravelUploadedFile::fake()->create('document.pdf', 100);

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriod->id)
        ->set('upload', $file)
        ->call('store')
        ->assertHasErrors('upload');

    expect(UploadedFile::count())->toBe(0);
});

test('user without permission cannot access upload page', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = $this->get('/archivos/subir');
    $response->assertStatus(403);
});
