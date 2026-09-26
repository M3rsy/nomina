<?php

use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Pest\TestSuite;
use Tests\TestCase;
use App\Livewire\Feriados\Index as HolidaysIndex;

uses()->beforeEach(function () {
    holidaysPresentationTestCase()->seed(PermissionRoleSeeder::class);
});

function holidaysPresentationTestCase(): TestCase
{
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('The current Pest test case is unavailable.');
    }

    return $test;
}

test('holidays page presents the Stitch-inspired labor calendar workspace with real data only', function () {
    $company = Company::factory()->create(['name' => 'Ullrich Ltd']);
    $admin = User::factory()->forCompany($company)->create([
        'password' => Hash::make('password'),
    ])->assignRole('company_admin');
    $activeHoliday = Holiday::factory()->forCompany($company)->create([
        'date' => '2026-09-15',
        'name' => 'Día de la Independencia',
        'description' => 'Feriado nacional',
        'is_active' => true,
    ]);
    $inactiveHoliday = Holiday::factory()->forCompany($company)->inactive()->create([
        'date' => '2026-09-09',
        'name' => 'Día del Maestro',
        'description' => 'Conmemoración docente',
    ]);

    app(CurrentCompany::class)->set($company);

    $response = holidaysPresentationTestCase()->actingAs($admin)->get(route('feriados.index'));

    $response->assertOk();
    $response->assertSeeHtml('data-holidays-index="workspace"');
    $response->assertSee('Feriados y Días Inhábiles');
    $response->assertSee('Calendario laboral');
    $response->assertSee('Feriados registrados');
    $response->assertSee('Activos visibles');
    $response->assertSee('Próximo feriado activo');
    $response->assertSee('Sincronización nómina');
    $response->assertSee('Día de la Independencia');
    $response->assertSee('Feriado nacional');
    $response->assertSee('Día del Maestro');
    $response->assertSee('Conmemoración docente');
    $response->assertSeeHtml('wire:model.live="search"');
    $response->assertSeeHtml('wire:click="openCreateModal"');
    $response->assertSeeHtml('wire:click="toggle('.$activeHoliday->id.')"');
    $response->assertSeeHtml('wire:click="edit('.$activeHoliday->id.')"');
    $response->assertSeeHtml('wire:click="confirmDelete('.$inactiveHoliday->id.')"');
    $response->assertDontSee('Sincronizar Calendario Nacional');
    $response->assertDontSee('Diario Oficial');
    $response->assertDontSee('Recargo 200%');
    $response->assertDontSee('Automatización Biométrica');
    $response->assertDontSee('Exportar CSV');
    $response->assertDontSee('Ver Manual Legal');
});

test('holidays page keeps the company-required guided state', function () {
    $superAdmin = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ])->assignRole('super_admin');

    app(CurrentCompany::class)->set(null);

    $response = holidaysPresentationTestCase()->actingAs($superAdmin)->get(route('feriados.index'));

    $response->assertOk();
    $response->assertSeeHtml('data-holidays-index="workspace"');
    $response->assertSee('Seleccioná una empresa para gestionar feriados.');
    $response->assertSeeHtml('wire:click="openCreateModal"');
});

test('holiday create modal presents the Stitch-inspired dialog while preserving real fields only', function () {
    $company = Company::factory()->create(['name' => 'Ullrich Ltd']);
    $admin = User::factory()->forCompany($company)->create([
        'password' => Hash::make('password'),
    ])->assignRole('company_admin');

    app(CurrentCompany::class)->set($company);

    Livewire::actingAs($admin)
        ->test(HolidaysIndex::class)
        ->call('openCreateModal')
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('aria-labelledby="holiday-modal-title"', false)
        ->assertSee('@keydown.escape.window="$wire.closeCreateModal()"', false)
        ->assertSee('Registrar Feriado o Día Inhábil')
        ->assertSee('Configurá fecha, nombre, descripción y estado dentro del calendario real.')
        ->assertSee('Fecha del feriado')
        ->assertSee('Nombre / denominación oficial')
        ->assertSee('Descripción')
        ->assertSee('Feriado activo en calendario')
        ->assertSee('Guardar feriado')
        ->assertSeeHtml('wire:model="formDate"')
        ->assertSeeHtml('wire:model="formName"')
        ->assertSeeHtml('wire:model="formDescription"')
        ->assertSeeHtml('wire:model="formIsActive"')
        ->assertSeeHtml('wire:click="save"')
        ->assertSeeHtml('wire:click="closeCreateModal"')
        ->assertDontSee('Recurrente anualmente')
        ->assertDontSee('Ámbito y Clasificación Legal')
        ->assertDontSee('Código de Trabajo')
        ->assertDontSee('recargo legal extraordinario del 200%')
        ->assertDontSee('Sincronización instantánea con reloj biométrico');
});
