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

function actingAsCompanyAdmin(Company $company): User
{
    $user = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('company_admin');

    return $user;
}

test('upload query preselection accepts only same-company uploadable periods', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $uploadablePeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $nonUploadablePeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $otherCompanyPeriod = PayPeriod::factory()->forCompany($otherCompany)->create(['status' => 'draft']);
    $admin = actingAsCompanyAdmin($company);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::withQueryParams(['pay_period_id' => $uploadablePeriod->id])
        ->test(Upload::class)
        ->assertSet('pay_period_id', $uploadablePeriod->id);

    Livewire::withQueryParams(['pay_period_id' => $nonUploadablePeriod->id])
        ->test(Upload::class)
        ->assertSet('pay_period_id', null);

    Livewire::withQueryParams(['pay_period_id' => $otherCompanyPeriod->id])
        ->test(Upload::class)
        ->assertSet('pay_period_id', null);
});

test('upload ignores malformed query preselection', function (mixed $queryValue) {
    $company = Company::factory()->create();
    $admin = actingAsCompanyAdmin($company);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::withQueryParams(['pay_period_id' => $queryValue])
        ->test(Upload::class)
        ->assertSet('pay_period_id', null);
})->with([
    'text' => 'not-an-id',
    'negative integer' => '-1',
    'decimal' => '1.5',
    'array' => [['1']],
]);

test('upload selector shows every uploadable status and excludes all other statuses', function () {
    $company = Company::factory()->create();
    $admin = actingAsCompanyAdmin($company);

    foreach (['draft', 'uploaded', 'validation_failed'] as $status) {
        PayPeriod::factory()->forCompany($company)->create([
            'name' => "Eligible {$status}",
            'status' => $status,
        ]);
    }

    foreach (['ready', 'validating', 'processing', 'processed', 'approved', 'exported', 'cancelled'] as $status) {
        PayPeriod::factory()->forCompany($company)->create([
            'name' => "Ineligible {$status}",
            'status' => $status,
        ]);
    }

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Upload::class)
        ->assertSee('Eligible draft')
        ->assertSee('Eligible uploaded')
        ->assertSee('Eligible validation_failed')
        ->assertDontSee('Ineligible ready')
        ->assertDontSee('Ineligible validating')
        ->assertDontSee('Ineligible processing')
        ->assertDontSee('Ineligible processed')
        ->assertDontSee('Ineligible approved')
        ->assertDontSee('Ineligible exported')
        ->assertDontSee('Ineligible cancelled');
});

test('ready period cannot receive attendance uploads', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'ready',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    $admin = actingAsCompanyAdmin($company);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $file = LaravelUploadedFile::fake()->createWithContent(
        'GLG_001.TXT',
        "1\t1\t13767\t\t1\t1\t01/19/2026 14:53:50\r\n",
    );

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriod->id)
        ->set('upload', $file)
        ->call('store')
        ->assertHasErrors('pay_period_id');

    expect(UploadedFile::count())->toBe(0);
});

test('guided upload explains real file contracts and enables submission only when ready', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $admin = actingAsCompanyAdmin($company);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Upload::class)
        ->assertSeeHtml('id="upload-step-period"')
        ->assertSeeHtml('id="upload-step-file"')
        ->assertSeeHtml('id="upload-step-result"')
        ->assertSee('GLG*.txt')
        ->assertSee('ATTLOG: *.dat')
        ->assertSee('5 MB')
        ->assertSee('empleados no encontrados')
        ->assertSee('fuera del rango de fechas')
        ->assertSee('registros duplicados')
        ->assertSeeHtml('href="'.route('nomina.index').'"');

    preg_match('/<button\b[^>]*id="upload-submit"[^>]*>/', $component->html(), $submitButton);
    expect($submitButton)->toHaveCount(1)
        ->and($submitButton[0])->toMatch('/\sdisabled(?:\s|=|>)/');

    $component->set('pay_period_id', $payPeriod->id);
    preg_match('/<button\b[^>]*id="upload-submit"[^>]*>/', $component->html(), $submitButton);
    expect($submitButton[0])->toMatch('/\sdisabled(?:\s|=|>)/');

    $file = LaravelUploadedFile::fake()->createWithContent('GLG_marcaciones.txt', str_repeat('x', 2048));
    $component->set('upload', $file)
        ->assertSee('GLG_marcaciones.txt')
        ->assertSee('2,0 KB');

    preg_match('/<button\b[^>]*id="upload-submit"[^>]*>/', $component->html(), $submitButton);
    expect($submitButton[0])->not->toMatch('/\sdisabled(?:\s|=|>)/');
});

test('upload without eligible periods guides the user to create one', function () {
    $company = Company::factory()->create();
    $admin = actingAsCompanyAdmin($company);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Upload::class)
        ->assertSee('No hay períodos disponibles para carga.')
        ->assertSee('Crear un período')
        ->assertSeeHtml('href="'.route('nomina.index').'"');
});

test('upload form relates validation errors and exposes loading feedback', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $admin = actingAsCompanyAdmin($company);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Upload::class)
        ->call('store')
        ->assertHasErrors(['pay_period_id', 'upload'])
        ->assertSeeHtml('id="pay-period-error" role="alert"')
        ->assertSeeHtml('id="upload-error" role="alert"')
        ->assertSeeHtml('wire:loading wire:target="upload"')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:loading wire:target="store"');

    expect($component->html())
        ->toMatch('/aria-describedby="[^"]*pay-period-help[^"]*pay-period-error[^"]*"/')
        ->toMatch('/aria-describedby="[^"]*attendance-file-contract[^"]*upload-error[^"]*"/');
});

test('upload treats the entire pay period end date as inclusive', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    $admin = actingAsCompanyAdmin($company);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $file = LaravelUploadedFile::fake()->createWithContent(
        'GLG_001.TXT',
        "1\t1\t13767\t\t1\t1\t01/31/2026 23:59:59\r\n",
    );

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriod->id)
        ->set('upload', $file)
        ->call('store')
        ->assertHasNoErrors();

    $uploadedFile = UploadedFile::sole();
    $payPeriod->refresh();
    $storedEndDate = (string) $payPeriod->getRawOriginal('end_date');

    expect($storedEndDate)->toStartWith('2026-01-31')
        ->not->toContain('23:59:59');

    expect($uploadedFile->status)->toBe('valid')
        ->and($uploadedFile->rawMarks()->sole()->status)->toBe('valid')
        ->and($uploadedFile->validation_summary['out_of_period'])->toBe(0)
        ->and($payPeriod->end_date->format('Y-m-d'))->toBe('2026-01-31')
        ->and($payPeriod->end_date->format('H:i:s'))->toBe('23:59:59');
});

test('company admin can upload glg file and records are parsed', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => 'TEST-001']);
    Employee::factory()->forCompany($company)->create(['external_id' => 'TEST-002']);

    $admin = actingAsCompanyAdmin($company);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $contents = file_get_contents(__DIR__.'/../../Fixtures/Attendance/GLG_minimal.txt');
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
    expect($uploadedFile->rawMarks()->count())->toBe(2);
});

test('company admin can upload attlog file and records are parsed', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => 'TEST-001']);
    Employee::factory()->forCompany($company)->create(['external_id' => 'TEST-002']);

    $admin = actingAsCompanyAdmin($company);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $contents = file_get_contents(__DIR__.'/../../Fixtures/Attendance/ATTLOG_minimal.dat');
    $file = LaravelUploadedFile::fake()->createWithContent('attlog.dat', $contents);

    Livewire::test(Upload::class)
        ->set('pay_period_id', (string) $payPeriod->id)
        ->set('upload', $file)
        ->call('store')
        ->assertHasNoErrors();

    $uploadedFile = UploadedFile::first();
    expect($uploadedFile)->not->toBeNull();
    expect($uploadedFile->rawMarks()->count())->toBe(2);
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
