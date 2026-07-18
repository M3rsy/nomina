<?php

use App\Livewire\Respaldos\Index;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('guest is redirected from respaldos', function () {
    $this->get('/respaldos')->assertRedirect('/login');
});

test('user without backups.run permission cannot access respaldos', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->actingAs($user)
        ->get('/respaldos')
        ->assertStatus(403);
});

test('lists backup zip files', function () {
    Storage::fake('backups');
    Storage::disk('backups')->put('nomina-2026-01-01.zip', 'content');
    Storage::disk('backups')->put('nomina-2026-01-02.zip', 'content');

    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('nomina-2026-01-01.zip')
        ->assertSee('nomina-2026-01-02.zip');
});

test('company admin cannot see restore button', function () {
    Storage::fake('backups');

    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertDontSee('Restaurar');
});

test('super admin sees restore button', function () {
    Storage::fake('backups');
    Storage::disk('backups')->put('nomina-test.zip', 'fake-content');

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(Index::class)
        ->assertSee('Restaurar');
});

test('super admin can generate backup', function () {
    Storage::fake('backups');
    config(['backup.enabled' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('backup:run', ['--disable-notifications' => true])
        ->andReturn(0);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(Index::class)
        ->call('generate')
        ->assertSee('Respaldo generado correctamente');
});

test('backup generation is skipped when disabled', function () {
    Storage::fake('backups');
    config(['backup.enabled' => false]);

    Artisan::shouldReceive('call')->never();

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(Index::class)
        ->call('generate')
        ->assertSee('deshabilitados');
});

test('download route returns backup file', function () {
    Storage::fake('backups');
    Storage::disk('backups')->put('nomina-2026-01-01.zip', 'zip-content');

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $response = $this->actingAs($super)->get('/respaldos/nomina-2026-01-01.zip/descargar');

    $response->assertOk();
    $response->assertDownload('nomina-2026-01-01.zip');
});

test('restore action logs blocked attempt', function () {
    Storage::fake('backups');

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(Index::class)
        ->call('confirmRestore', 'nomina-2026-01-01.zip')
        ->assertSet('showRestoreModal', true)
        ->call('restore')
        ->assertSet('showRestoreModal', false)
        ->assertSee('Función no implementada en MVP');
});
