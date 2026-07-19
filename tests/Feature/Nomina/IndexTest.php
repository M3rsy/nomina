<?php

use App\Livewire\Nomina\Index;
use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('company admin can view nomina index of own company', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'uploaded']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    $this->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertSee($payPeriod->name)
        ->assertSee('/nomina/'.$payPeriod->id.'/revisar');
});

test('nomina index translates stored period statuses for display', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create(['status' => 'validation_failed']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    $this->actingAs($admin)
        ->get(route('nomina.index'))
        ->assertOk()
        ->assertSee('Validación con errores');
});

test('ready period does not expose an attendance upload action', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    $this->actingAs($admin)
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

    $this->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertDontSee('Empresa B');
});

test('super admin without active company sees empty nomina index', function () {
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    app(CurrentCompany::class)->set(null);

    $this->actingAs($superAdmin)
        ->get('/nomina')
        ->assertOk();
});

test('create period control is enabled and connected to the inline form', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    $html = $this->actingAs($admin)
        ->get('/nomina')
        ->assertOk()
        ->assertSee('Crear período')
        ->getContent();

    preg_match('/<button\b[^>]*id="create-period-trigger"[^>]*>/', $html, $button);

    expect($button)->toHaveCount(1)
        ->and($button[0])->toContain('wire:click="openCreateForm"')
        ->and($button[0])->not->toContain('disabled');

    Livewire::test(Index::class)
        ->call('openCreateForm')
        ->assertSeeHtml('id="create-period-form"')
        ->assertSeeHtml('wire:submit="store"');
});

test('period creation form exposes no client-controlled company or status fields', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
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

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Index::class)
        ->set('name', 'Primera quincena de agosto')
        ->set('start_date', '2026-08-01')
        ->set('end_date', '2026-08-15')
        ->call('store')
        ->assertHasNoErrors();

    $payPeriod = PayPeriod::withoutCompanyScope()->sole();

    expect($payPeriod->company_id)->toBe($company->id)
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

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set($activeCompany);

    Livewire::test(Index::class)
        ->set('name', 'Período de empresa activa')
        ->set('start_date', '2026-09-01')
        ->set('end_date', '2026-09-30')
        ->call('store')
        ->assertHasNoErrors();

    $payPeriod = PayPeriod::withoutCompanyScope()->sole();

    expect($payPeriod->company_id)->toBe($activeCompany->id)
        ->and($payPeriod->company_id)->not->toBe($otherCompany->id)
        ->and($payPeriod->status)->toBe('draft');
});

test('super admin without an active company cannot create a period', function () {
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $this->actingAs($superAdmin);
    app(CurrentCompany::class)->set(null);

    Livewire::test(Index::class)
        ->set('name', 'Período sin empresa')
        ->set('start_date', '2026-09-01')
        ->set('end_date', '2026-09-30')
        ->call('store')
        ->assertStatus(403);

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(0);
});

test('company admin with an unresolvable company cannot create a period', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $company->delete();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set(null);

    Livewire::test(Index::class)
        ->set('name', 'Período huérfano')
        ->set('start_date', '2026-09-01')
        ->set('end_date', '2026-09-30')
        ->call('store')
        ->assertStatus(403);

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(0);
});

test('user without manage permission cannot invoke period creation directly', function () {
    $company = Company::factory()->create();
    $user = User::factory()->forCompany($company)->create();
    $user->givePermissionTo('pay_periods.view');

    $this->actingAs($user);
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

    $this->actingAs($admin);
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

test('period creation reports a same-company slug collision without adding a row', function () {
    $company = Company::factory()->create();
    PayPeriod::factory()->forCompany($company)->create([
        'slug' => 'primera-quincena-de-agosto',
    ]);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Index::class)
        ->set('name', 'Primera quincena de agosto')
        ->set('start_date', '2026-08-01')
        ->set('end_date', '2026-08-15')
        ->call('store')
        ->assertHasErrors('name');

    expect(PayPeriod::withoutCompanyScope()->count())->toBe(1);
});

test('manual period creation validates its input contract', function (array $values, string $field, string $rule, string $message) {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
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

test('user without pay periods view permission cannot access nomina index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/nomina')
        ->assertForbidden();
});
