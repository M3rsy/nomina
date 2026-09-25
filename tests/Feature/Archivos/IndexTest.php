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
use Livewire\Livewire;
use Pest\TestSuite;
use Tests\TestCase;

uses()->beforeEach(function () {
    archivosIndexTestCase()->seed(PermissionRoleSeeder::class);
});

function archivosIndexTestCase(): TestCase
{
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('The current Pest test case is unavailable.');
    }

    return $test;
}

test('super admin cannot list uploaded files without an active company context', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'original_name' => 'GLOBAL-LEAK.TXT',
    ]);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    archivosIndexTestCase()->actingAs($superAdmin);
    app(CurrentCompany::class)->set(null);

    Livewire::test(Index::class)
        ->assertForbidden();
});

test('super admin stale company selection is cleared and never lists global files', function (?Closure $staleSelection) {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'original_name' => 'GLOBAL-LEAK.TXT',
    ]);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    archivosIndexTestCase()->actingAs($superAdmin);
    session(['active_company_id' => $staleSelection?->call(archivosIndexTestCase()) ?? Company::factory()->inactive()->create()->id]);

    Livewire::test(Index::class)
        ->assertForbidden()
        ->assertDontSee('GLOBAL-LEAK.TXT');

    expect(session()->has('active_company_id'))->toBeFalse();
})->with([
    'inactive company' => null,
    'missing company' => fn () => Company::query()->max('id') + 1000,
]);

test('super admin lists only the active company files', function () {
    $activeCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $activePayPeriod = PayPeriod::factory()->forCompany($activeCompany)->create();
    $otherPayPeriod = PayPeriod::factory()->forCompany($otherCompany)->create();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $files = collect(range(1, 11))->map(fn (int $number) => UploadedFile::factory()
        ->forCompany($activeCompany)
        ->forPayPeriod($activePayPeriod)
        ->create([
            'original_name' => sprintf('FILE-%02d.TXT', $number),
            'created_at' => '2026-01-01 12:00:00',
        ]));
    UploadedFile::factory()->forCompany($otherCompany)->forPayPeriod($otherPayPeriod)->create([
        'original_name' => 'OTHER-COMPANY.TXT',
        'created_at' => '2026-01-01 12:00:00',
    ]);

    archivosIndexTestCase()->actingAs($superAdmin);
    app(CurrentCompany::class)->set($activeCompany);

    Livewire::test(Index::class)
        ->assertSeeInOrder($files->reverse()->take(10)->pluck('original_name')->all())
        ->assertDontSee('FILE-01.TXT')
        ->assertDontSee('OTHER-COMPANY.TXT')
        ->assertSeeHtml('wire:click="nextPage(\'page\')"')
        ->call('setPage', 2)
        ->assertSee('FILE-01.TXT')
        ->assertDontSee('FILE-11.TXT')
        ->assertDontSee('OTHER-COMPANY.TXT')
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

    archivosIndexTestCase()->actingAs($admin);
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

    archivosIndexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(Index::class)
        ->assertSeeInOrder(['WITH-SEVEN.TXT', 'WITH-THREE.TXT'])
        ->assertSeeHtml('>7</td>')
        ->assertSeeHtml('>3</td>');

    $rawMarkQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query) => str_contains($query, 'raw_marks'));
    DB::disableQueryLog();

    expect($rawMarkQueries)->toHaveCount(1);
});

test('company admin sees the uploaded files management workspace', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['name' => 'January 2026']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'original_name' => 'attendance-january.csv',
        'stored_name' => 'tenant/attendance-january.csv',
        'status' => 'valid',
    ]);
    RawMark::factory()->count(3)->forCompany($company)->forPayPeriod($payPeriod)
        ->forUploadedFile($file)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    archivosIndexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = archivosIndexTestCase()->get('/archivos');

    $response->assertOk();
    $response->assertSee('Control de Asistencia / Relojes y Biometría');
    $response->assertSee('Archivos de marcas');
    $response->assertSee('Volver a períodos');
    $response->assertSee('Cargar nuevo archivo');
    $response->assertSee('Auditoría de cargas');
    $response->assertSee('January 2026');
    $response->assertSee('attendance-january.csv');
    $response->assertSee('3');
    $response->assertSeeHtml('data-files-index="workspace"');
    $response->assertSeeHtml('data-files-section="summary"');
    $response->assertSeeHtml('data-files-section="filters"');
    $response->assertSeeHtml('data-files-section="listing"');
    $response->assertSeeHtml('href="'.route('nomina.index').'"');
    $response->assertSeeHtml('href="'.route('archivos.upload').'"');
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

    archivosIndexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($companyA);

    $response = archivosIndexTestCase()->get('/archivos');
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

    archivosIndexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = archivosIndexTestCase()->get('/archivos?status=valid');
    $response->assertOk();
    $response->assertSee('valid.txt');
    $response->assertDontSee('invalid.txt');
});

test('index filters by pay period', function () {
    $company = Company::factory()->create();
    $payPeriodA = PayPeriod::factory()->forCompany($company)->create();
    $payPeriodB = PayPeriod::factory()->forCompany($company)->create();

    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriodA)->create([
        'original_name' => 'PERIOD-ALPHA-UPLOAD.CSV',
        'stored_name' => 'stored-alpha-visible-row.csv',
    ]);
    UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriodB)->create([
        'original_name' => 'PERIOD-BRAVO-HIDDEN.CSV',
        'stored_name' => 'stored-bravo-filtered-row.csv',
    ]);

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    archivosIndexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = archivosIndexTestCase()->get('/archivos?pay_period_id='.$payPeriodA->id);
    $response->assertOk();
    $response->assertSee('PERIOD-ALPHA-UPLOAD.CSV');
    $response->assertSee('stored-alpha-visible-row.csv');
    $response->assertDontSee('PERIOD-BRAVO-HIDDEN.CSV');
    $response->assertDontSee('stored-bravo-filtered-row.csv');
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

    archivosIndexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $response = archivosIndexTestCase()->get('/archivos?search=GLG');
    $response->assertOk();
    $response->assertSee('GLG_001.TXT');
    $response->assertDontSee('attlog.dat');
});

test('company admin deletes an uploaded file and deactivates its marks with a reason', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'status' => 'valid',
        'notes' => 'Original validation note',
    ]);
    $evidence = $mark->only([
        'company_id',
        'pay_period_id',
        'uploaded_file_id',
        'employee_external_id',
        'raw_line',
        'source',
        'row_number',
    ]);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    archivosIndexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->call('openDeleteConfirmation', $file->id)
        ->call('deleteFile')
        ->assertHasErrors(['deletionReason' => 'required'])
        ->set('deletionReason', 'Archivo duplicado')
        ->call('deleteFile')
        ->assertHasNoErrors();

    $deletedFile = UploadedFile::withTrashed()->findOrFail($file->id);
    $mark->refresh();

    expect($deletedFile->trashed())->toBeTrue()
        ->and($deletedFile->deletion_reason)->toBe('Archivo duplicado')
        ->and($mark->status)->toBe('deleted')
        ->and($mark->notes)->toContain('Original validation note')
        ->and($mark->notes)->toContain('Archivo eliminado: Archivo duplicado')
        ->and($mark->only(array_keys($evidence)))->toBe($evidence);
});
