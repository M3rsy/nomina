<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('companies table is contained in a named keyboard scroll region', function () {
    Company::factory()->create();
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $response = $this->actingAs($super)->get(route('empresas.index'));

    $response->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $tables = (new DOMXPath($document))->query(
        '//*[@role="region" and @aria-labelledby="companies-heading" and @tabindex="0"'
        .' and contains(concat(" ", normalize-space(@class), " "), " overflow-x-auto ")]//table',
    );

    expect($tables->length)->toBe(1);
});
