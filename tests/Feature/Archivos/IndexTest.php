<?php

use App\Livewire\Archivos\Index;
use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('super admin paginates all company files with stable ordering', function () {
    $companies = Company::factory()->count(2)->create();
    $payPeriods = $companies->map(fn (Company $company) => PayPeriod::factory()->forCompany($company)->create());
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $files = collect(range(1, 11))->map(fn (int $number) => UploadedFile::factory()
        ->forCompany($companies[$number % 2])
        ->forPayPeriod($payPeriods[$number % 2])
        ->create([
            'original_name' => sprintf('FILE-%02d.TXT', $number),
            'created_at' => '2026-01-01 12:00:00',
        ]));

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set(null);

    Livewire::test(Index::class)
        ->assertSeeInOrder($files->reverse()->take(10)->pluck('original_name')->all())
        ->assertDontSee('FILE-01.TXT')
        ->assertSeeHtml('wire:click="nextPage(\'page\')"')
        ->call('setPage', 2)
        ->assertSee('FILE-01.TXT')
        ->assertDontSee('FILE-11.TXT')
        ->assertSeeHtml('wire:click="previousPage(\'page\')"');
});

test('company file filters reset page two and remain tenant scoped', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $filteredPayPeriod = PayPeriod::factory()->forCompany($company)->create();
    $otherPayPeriod = PayPeriod::factory()->forCompany($otherCompany)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    collect(range(1, 11))->each(fn (int $number) => UploadedFile::factory()
        ->forCompany($company)
        ->forPayPeriod($number === 11 ? $filteredPayPeriod : $payPeriod)
        ->create([
            'original_name' => sprintf('FILE-%02d.TXT', $number),
            'status' => $number === 11 ? 'valid' : 'pending',
            'created_at' => sprintf('2026-01-%02d 12:00:00', $number),
        ]));
    UploadedFile::factory()->forCompany($otherCompany)->forPayPeriod($otherPayPeriod)
        ->create(['original_name' => 'OTHER-COMPANY.TXT']);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    foreach (['search' => 'FILE-11', 'status' => 'valid', 'pay_period_id' => $filteredPayPeriod->id] as $property => $value) {
        Livewire::test(Index::class)
            ->call('setPage', 2)
            ->set($property, $value)
            ->assertSee('FILE-11.TXT')
            ->assertDontSee('OTHER-COMPANY.TXT');
    }
});

test('file mark totals render without per-row count queries', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $fileWithThree = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)
        ->create(['original_name' => 'WITH-THREE.TXT']);
    $fileWithSeven = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)
        ->create(['original_name' => 'WITH-SEVEN.TXT']);

    RawMark::factory()->count(3)->forCompany($company)->forPayPeriod($payPeriod)
        ->forUploadedFile($fileWithThree)->create();
    RawMark::factory()->count(7)->forCompany($company)->forPayPeriod($payPeriod)
        ->forUploadedFile($fileWithSeven)->create();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(Index::class)
        ->assertSeeInOrder(['WITH-SEVEN.TXT', 'WITH-THREE.TXT'])
        ->assertSeeHtml('<td class="px-4 py-2">7</td>')
        ->assertSeeHtml('<td class="px-4 py-2">3</td>');

    $rawMarkQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query) => str_contains($query, 'raw_marks'));
    DB::disableQueryLog();

    expect($rawMarkQueries)->toHaveCount(1);
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
