<?php

use App\Livewire\Nomina\Index;
use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use App\Services\UploadedAttendanceFileIngestor;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Pest\TestSuite;
use Tests\TestCase;

function indexTestCase(): TestCase
{
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('The current Pest test case is unavailable.');
    }

    return $test;
}

beforeEach(function () {
    indexTestCase()->seed(PermissionRoleSeeder::class);
    Storage::fake('local');
});

test('company admin can view nomina index of own company', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'uploaded']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    indexTestCase()->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertSee($payPeriod->name)
        ->assertSee('/nomina/'.$payPeriod->id.'/revisar');
});

test('company admin paginates own periods with stable ordering', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $payPeriods = collect(range(1, 11))->map(fn (int $number) => PayPeriod::factory()
        ->forCompany($company)
        ->create([
            'slug' => sprintf('period-%02d', $number),
            'name' => sprintf('Period %02d', $number),
            'start_date' => '2026-01-01',
        ]));
    PayPeriod::factory()->forCompany($otherCompany)->create(['name' => 'Other company period']);

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->assertSeeInOrder($payPeriods->reverse()->take(10)->pluck('name')->all())
        ->assertDontSee('Period 01')
        ->assertDontSee('Other company period')
        ->assertSeeHtml('wire:click="nextPage(\'page\')"')
        ->call('setPage', 2)
        ->assertSee('Period 01')
        ->assertDontSee('Period 11')
        ->assertSeeHtml('wire:click="previousPage(\'page\')"');
});

test('nomina index translates stored period statuses for display', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create(['status' => 'validation_failed']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    indexTestCase()->actingAs($admin)
        ->get(route('nomina.index'))
        ->assertOk()
        ->assertSee('Validación con errores');
});

test('ready period does not expose an attendance upload action', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    indexTestCase()->actingAs($admin)
        ->get(route('nomina.index'))
        ->assertOk()
        ->assertSee($payPeriod->name)
        ->assertDontSee(route('archivos.upload', ['pay_period_id' => $payPeriod->id]), escape: false);
});

test('company admin cannot view nomina index of other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    PayPeriod::factory()->forCompany($companyB)->create();
    $admin = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($companyA);

    indexTestCase()->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertDontSee('Empresa B');
});

test('super admin without active company receives a guided payroll state', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create(['name' => 'Private payroll period']);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    app(CurrentCompany::class)->set(null);

    indexTestCase()->actingAs($superAdmin)
        ->get('/nomina')
        ->assertOk()
        ->assertSeeText('Seleccioná una empresa para continuar')
        ->assertSeeText('La nómina siempre corresponde a una empresa activa.')
        ->assertSeeText('Seleccionar empresa')
        ->assertSee('x-on:click.stop="$dispatch(\'open-company-selector\')"', escape: false)
        ->assertSee('@open-company-selector.window="if (window.innerWidth >= 1280) { open = true; $nextTick(() => $refs.companyTrigger.focus()) }"', escape: false)
        ->assertSee('@open-company-selector.window="if (window.innerWidth < 1280) { mobileOpen = true; $nextTick(() => $refs.mobileCompanyHeading.focus()) }"', escape: false)
        ->assertSee('x-ref="mobileCompanyHeading"', escape: false)
        ->assertSee('href="'.route('dashboard').'"', escape: false)
        ->assertDontSee('id="create-period-trigger"', escape: false)
        ->assertDontSee('aria-label="Etapas del flujo de nómina"', escape: false)
        ->assertDontSeeText('Períodos existentes')
        ->assertDontSeeText('Private payroll period');
});

test('invalid active company context falls back to the guided payroll state', function (string $context) {
    $companyId = $context === 'inactive'
        ? Company::factory()->inactive()->create()->id
        : 999999;
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    indexTestCase()->withSession(['active_company_id' => $companyId])
        ->actingAs($superAdmin)
        ->get('/nomina')
        ->assertOk()
        ->assertSeeText('Seleccioná una empresa para continuar')
        ->assertSessionMissing('active_company_id');
})->with(['missing', 'inactive']);

test('company-scoped user without a resolvable company remains forbidden', function () {
    $admin = User::factory()->create(['company_id' => null]);
    $admin->givePermissionTo('pay_periods.view');

    indexTestCase()->actingAs($admin)
        ->get('/nomina')
        ->assertForbidden();
});

test('create period control is enabled and connected to the inline form', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    $html = indexTestCase()->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertSee('Crear período')
        ->getContent();

    preg_match('/<button\b[^>]*id="create-period-trigger"[^>]*>/', $html, $button);

    expect($button)->toHaveCount(1)
        ->and($button[0])->toContain('wire:click="openCreateForm"')
        ->and($button[0])->not->toMatch('/\sdisabled(?:\s|=|>)/');

    Livewire::test(Index::class)
        ->call('openCreateForm')
        ->assertSeeHtml('id="create-period-form"')
        ->assertSeeHtml('wire:submit="store"');
});

test('period creation form exposes no client-controlled company or status fields', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->call('openCreateForm')
        ->assertDontSeeHtml('wire:model="company_id"')
        ->assertDontSeeHtml('wire:model="status"')
        ->assertDontSeeHtml('name="company_id"')
        ->assertDontSeeHtml('name="status"');
});

test('company admin can create a draft period for own company and continue to upload', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(0);

    $component = Livewire::test(Index::class)
        ->set('name', 'Primera quincena de agosto')
        ->set('start_date', '2026-08-01')
        ->set('end_date', '2026-08-15')
        ->call('store')
        ->assertHasNoErrors();

    $payPeriod = PayPeriod::withoutCompanyScope()->sole();

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(1)
        ->and($payPeriod->company_id)->toBe($company->id)
        ->and($payPeriod->slug)->toBe('primera-quincena-de-agosto')
        ->and($payPeriod->name)->toBe('Primera quincena de agosto')
        ->and($payPeriod->start_date->toDateString())->toBe('2026-08-01')
        ->and($payPeriod->end_date->toDateString())->toBe('2026-08-15')
        ->and($payPeriod->status)->toBe('draft');

    $component->assertRedirectToRoute('archivos.upload', [
        'pay_period_id' => $payPeriod->id,
    ]);
});

test('super admin creates a period only for the selected active company', function () {
    $activeCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    indexTestCase()->actingAs($superAdmin);
    app(CurrentCompany::class)->set($activeCompany);

    Livewire::test(Index::class)
        ->set('name', 'Período de empresa activa')
        ->set('start_date', '2026-09-01')
        ->set('end_date', '2026-09-30')
        ->call('store')
        ->assertHasNoErrors();

    $payPeriod = PayPeriod::withoutCompanyScope()->sole();

    expect(PayPeriod::withoutCompanyScope()->where('company_id', $activeCompany->id)->count())->toBe(1)
        ->and(PayPeriod::withoutCompanyScope()->where('company_id', $otherCompany->id)->count())->toBe(0)
        ->and($payPeriod->company_id)->toBe($activeCompany->id)
        ->and($payPeriod->company_id)->not->toBe($otherCompany->id)
        ->and($payPeriod->status)->toBe('draft');
});

test('super admin without an active company cannot access period creation', function () {
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    indexTestCase()->actingAs($superAdmin);
    app(CurrentCompany::class)->set(null);

    indexTestCase()->get('/nomina')
        ->assertOk()
        ->assertDontSee('id="create-period-trigger"', escape: false);

    Livewire::test(Index::class)
        ->set('name', 'Período sin empresa')
        ->set('start_date', '2026-09-01')
        ->set('end_date', '2026-09-30')
        ->call('store')
        ->assertStatus(403);

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(0);
});

test('company admin with an unresolvable company is denied period creation', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $company->delete();

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set(null);

    expect(Gate::forUser($admin)->denies('create', PayPeriod::class))->toBeTrue()
        ->and(PayPeriod::withoutCompanyScope()->count())->toBe(0);
});

test('user without manage permission cannot invoke period creation directly', function () {
    $company = Company::factory()->create();
    $user = User::factory()->forCompany($company)->create();
    $user->givePermissionTo('pay_periods.view');

    indexTestCase()->actingAs($user);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->assertDontSee('Crear período')
        ->set('name', 'Período no autorizado')
        ->set('start_date', '2026-09-01')
        ->set('end_date', '2026-09-30')
        ->call('store')
        ->assertStatus(403);

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(0);
});

test('period creation rejects an end date before the start date', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->set('name', 'Período inválido')
        ->set('start_date', '2026-10-15')
        ->set('end_date', '2026-10-01')
        ->call('store')
        ->assertHasErrors(['end_date' => 'after_or_equal'])
        ->assertSee('La fecha de fin debe ser igual o posterior a la fecha de inicio.');

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(0);
});

test('period creation rejects dates that overlap another company period', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-15',
    ]);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->set('name', 'Período superpuesto')
        ->set('start_date', '2026-08-15')
        ->set('end_date', '2026-08-31')
        ->call('store')
        ->assertHasErrors('start_date')
        ->assertSee('Las fechas se superponen con otro período de la empresa.');

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(1);
});

test('period creation reports a same-company slug collision without adding a row', function () {
    $company = Company::factory()->create();
    $existing = PayPeriod::factory()->forCompany($company)->create([
        'slug' => 'primera-quincena-de-agosto',
    ]);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $periodSnapshot = $existing->fresh()->getAttributes();

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->set('name', 'Primera quincena de agosto')
        ->set('start_date', '2026-08-01')
        ->set('end_date', '2026-08-15')
        ->call('store')
        ->assertHasErrors('name');

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(1)
        ->and($existing->fresh()->getAttributes())->toBe($periodSnapshot);
});

test('manual period creation validates its input contract', function (array $values, string $field, string $rule, string $message) {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->set('name', $values['name'])
        ->set('start_date', $values['start_date'])
        ->set('end_date', $values['end_date'])
        ->call('store')
        ->assertHasErrors([$field => $rule])
        ->assertSee($message);

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(0);
})->with([
    'required name' => [
        ['name' => '', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15'],
        'name',
        'required',
        'Ingresá un nombre para el período.',
    ],
    'name length' => [
        ['name' => str_repeat('a', 121), 'start_date' => '2026-11-01', 'end_date' => '2026-11-15'],
        'name',
        'max',
        'El nombre no puede superar los 120 caracteres.',
    ],
    'required start date' => [
        ['name' => 'Noviembre', 'start_date' => '', 'end_date' => '2026-11-15'],
        'start_date',
        'required',
        'Ingresá la fecha de inicio.',
    ],
    'valid start date' => [
        ['name' => 'Noviembre', 'start_date' => 'not-a-date', 'end_date' => '2026-11-15'],
        'start_date',
        'date',
        'Ingresá una fecha de inicio válida.',
    ],
    'required end date' => [
        ['name' => 'Noviembre', 'start_date' => '2026-11-01', 'end_date' => ''],
        'end_date',
        'required',
        'Ingresá la fecha de fin.',
    ],
    'valid end date' => [
        ['name' => 'Noviembre', 'start_date' => '2026-11-01', 'end_date' => 'not-a-date'],
        'end_date',
        'date',
        'Ingresá una fecha de fin válida.',
    ],
]);

test('period overview renders canonical status copy and five workflow phases', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $statuses = [
        'draft' => ['Borrador', 'El período fue creado y puede recibir archivos de asistencia.'],
        'uploaded' => ['Archivo cargado', 'Hay un archivo cargado y se permite cargar otro; esto no confirma la validación.'],
        'validating' => ['Validando', 'La asistencia está en revisión y deben resolverse los bloqueos visibles.'],
        'validation_failed' => ['Validación con errores', 'La validación falló; corrija el archivo y vuelva a cargarlo.'],
        'ready' => ['Listo', 'La revisión está lista y el procesamiento puede solicitarse si no hay bloqueos.'],
        'processing' => ['Procesando', 'El cálculo está activo y la asistencia no puede editarse.'],
        'processed' => ['Procesado', 'Los resultados actuales están congelados y disponibles para revisión y aprobación.'],
        'approved' => ['Aprobado', 'La nómina está aprobada, bloqueada y disponible para exportar.'],
        'exported' => ['Exportado', 'La nómina fue exportada, permanece bloqueada y puede exportarse nuevamente.'],
        'cancelled' => ['Cancelado', 'El período está cancelado y no admite edición.'],
    ];

    foreach ($statuses as $status => $_) {
        PayPeriod::factory()->forCompany($company)->create(['name' => "Estado {$status}", 'status' => $status]);
    }

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Index::class)
        ->assertSeeHtml('aria-label="Flujo de nómina"')
        ->assertSee('Período')
        ->assertSee('Carga')
        ->assertSee('Revisión')
        ->assertSee('Proceso')
        ->assertSee('Aprobación y exportación');

    foreach ($statuses as [$label, $copy]) {
        $component->assertSee($label)->assertSee($copy);
    }
});

test('period actions require their matching permissions and preserve route parameters', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $user = User::factory()->forCompany($company)->create();
    $user->givePermissionTo(['pay_periods.view', 'files.upload']);

    indexTestCase()->actingAs($user);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->assertSeeHtml('href="'.route('archivos.upload', ['pay_period_id' => $period->id]).'"')
        ->assertSeeHtml('wire:navigate')
        ->assertDontSeeHtml('href="'.route('nomina.revisar', $period).'"')
        ->assertDontSee('Crear período')
        ->assertDontSee('Eliminar');
    indexTestCase()->get(route('nomina.revisar', $period))->assertForbidden();

    $user->syncPermissions(['pay_periods.view', 'marks.manage']);

    Livewire::test(Index::class)
        ->assertDontSeeHtml('href="'.route('archivos.upload', ['pay_period_id' => $period->id]).'"')
        ->assertSeeHtml('href="'.route('nomina.revisar', $period).'"')
        ->assertSeeHtml('wire:navigate');
    indexTestCase()->get(route('archivos.upload', ['pay_period_id' => $period->id]))->assertForbidden();
});

test('period overview exposes the exact upload matrix', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $statuses = ['draft', 'uploaded', 'validation_failed', 'validating', 'ready', 'processing', 'processed', 'approved', 'exported', 'cancelled'];

    foreach ($statuses as $status) {
        PayPeriod::factory()->forCompany($company)->create(['name' => "Estado {$status}", 'status' => $status]);
    }

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);
    $html = Livewire::test(Index::class)->html();

    foreach (PayPeriod::query()->get() as $period) {
        $uploadUrl = route('archivos.upload', ['pay_period_id' => $period->id]);
        $expectedCount = in_array($period->status, ['draft', 'uploaded', 'validation_failed'], true) ? 2 : 0;

        expect(substr_count($html, $uploadUrl))->toBe($expectedCount);
    }
});

test('period list has distinct desktop and narrow presentations with useful empty states', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->assertSee('Todavía no hay períodos de nómina.')
        ->assertSee('Crear el primer período')
        ->assertSeeHtml('data-period-desktop-list')
        ->assertSeeHtml('data-period-mobile-list');

    $viewer = User::factory()->forCompany($company)->create();
    $viewer->givePermissionTo('pay_periods.view');
    indexTestCase()->actingAs($viewer);

    Livewire::test(Index::class)
        ->assertSee('Todavía no hay períodos de nómina.')
        ->assertDontSee('Crear el primer período');
});

test('deletion confirmation has an accessible idle-safe lifecycle', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Index::class)
        ->call('openDeleteConfirmation', $period->id)
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-labelledby="delete-period-heading"')
        ->assertSeeHtml('aria-describedby="delete-period-description"')
        ->assertSeeHtml('for="period-deletion-reason"')
        ->assertSeeHtml('x-ref="deleteReason"')
        ->assertSeeHtml('x-on:keydown.escape.window')
        ->assertSeeHtml('x-on:payroll-delete-closed.window')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:target="deletePeriod"')
        ->call('closeDeleteConfirmation')
        ->assertSet('deletingPeriodId', null);

    expect($period->fresh()->trashed())->toBeFalse();
});

test('cross-tenant period identifiers cannot open deletion confirmation', function () {
    $company = Company::factory()->create();
    $otherPeriod = PayPeriod::factory()->forCompany(Company::factory()->create())->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    expect(fn () => Livewire::test(Index::class)->call('openDeleteConfirmation', $otherPeriod->id))
        ->toThrow(ModelNotFoundException::class);
});

test('user without pay periods view permission cannot access nomina index', function () {
    $user = User::factory()->create();

    indexTestCase()->actingAs($user)
        ->get('/nomina')
        ->assertForbidden();
});

test('company admin deletes a payroll period and its files with a reason', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $contents = "1\t1\t13767\t\t1\t1\t01/19/2026 14:53:50\r\n";
    $file = app(UploadedAttendanceFileIngestor::class)->ingest(
        $company,
        $payPeriod,
        $admin,
        LaravelUploadedFile::fake()->createWithContent('GLG_001.TXT', $contents),
    );

    indexTestCase()->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->call('openDeleteConfirmation', $payPeriod->id)
        ->call('deletePeriod')
        ->assertHasErrors(['deletionReason' => 'required'])
        ->set('deletionReason', str_repeat('a', 501))
        ->call('deletePeriod')
        ->assertHasErrors(['deletionReason' => 'max'])
        ->set('deletionReason', 'Periodo creado por error')
        ->call('deletePeriod')
        ->assertHasNoErrors();

    expect($payPeriod->fresh()->trashed())->toBeTrue()
        ->and($payPeriod->fresh()->deletion_reason)->toBe('Periodo creado por error')
        ->and($payPeriod->fresh()->deleted_by)->toBe($admin->id)
        ->and($file->fresh()->trashed())->toBeTrue()
        ->and($file->fresh()->deletion_reason)->toBe('Periodo creado por error')
        ->and($file->fresh()->deleted_by)->toBe($admin->id)
        ->and(AuditLogEntry::query()->where('type', 'deletion')->count())->toBe(2)
        ->and(AuditLogEntry::query()->where('actor_id', $admin->id)->count())->toBe(2)
        ->and(AuditLogEntry::query()->whereJsonContains('metadata->reason', 'Periodo creado por error')->count())->toBe(2);

    $replacementPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);
    $replacement = app(UploadedAttendanceFileIngestor::class)->ingest(
        $company,
        $replacementPeriod,
        $admin,
        LaravelUploadedFile::fake()->createWithContent('GLG_002.TXT', $contents),
    );

    expect($replacement->sha256)->toBe($file->sha256)
        ->and($replacement->pay_period_id)->toBe($replacementPeriod->id)
        ->and(UploadedFile::count())->toBe(1)
        ->and(UploadedFile::withTrashed()->count())->toBe(2);
});
