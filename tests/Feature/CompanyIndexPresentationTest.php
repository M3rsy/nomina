<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Pest\TestSuite;
use Tests\TestCase;

uses()->beforeEach(function () {
    companyIndexTestCase()->seed(PermissionRoleSeeder::class);
});

function companyIndexTestCase(): TestCase
{
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('The current Pest test case is unavailable.');
    }

    return $test;
}

test('companies table is contained in a named keyboard scroll region', function () {
    Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $response = companyIndexTestCase()->actingAs($super)->get(route('empresas.index'));

    $response->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $tables = (new DOMXPath($document))->query(
        '//*[@role="region" and @aria-labelledby="companies-heading" and @tabindex="0"'
        .' and contains(concat(" ", normalize-space(@class), " "), " overflow-x-auto ")]//table',
    );

    expect($tables->length)->toBe(1);
});

test('companies page presents the Stitch-inspired corporate management workspace with real company data', function () {
    $active = Company::factory()->create([
        'name' => 'Ullrich Ltd',
        'slug' => 'ullrich-ltd',
        'legal_id' => 'RTN-6979343',
        'is_active' => true,
    ]);
    Company::factory()->inactive()->create([
        'name' => 'Agroindustrias del Norte',
        'slug' => 'agro-norte',
        'legal_id' => '0101-2018-445566',
    ]);
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $response = companyIndexTestCase()->withSession(['active_company_id' => $active->id])
        ->actingAs($super)
        ->get(route('empresas.index'));

    $response->assertOk()
        ->assertSee('data-companies-index="workspace"', false)
        ->assertSee('Gestión Empresarial')
        ->assertSee('Directorio Corporativo Multi-Entidad')
        ->assertSee('Empresas registradas')
        ->assertSee('Empresas visibles')
        ->assertSee('Empresa activa')
        ->assertSee('Ullrich Ltd')
        ->assertSee('RTN-6979343')
        ->assertSee('Agroindustrias del Norte')
        ->assertSee('wire:model.live="search"', false)
        ->assertSee('wire:click="toggle(', false)
        ->assertSee('wire:click="delete(', false)
        ->assertDontSee('Masa Salarial Consolidada')
        ->assertDontSee('Exportar informe fiscal');
});
