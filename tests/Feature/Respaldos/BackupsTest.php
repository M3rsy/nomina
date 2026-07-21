<?php

use App\Livewire\Respaldos\Index;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('seeder revokes global backup access without changing unrelated role permissions', function () {
    $companyAdmin = Role::findByName('company_admin');
    $customPermission = Permission::create([
        'name' => 'reports.export',
        'guard_name' => 'web',
    ]);
    $companyAdmin->givePermissionTo('backups.run', $customPermission);

    $this->seed(PermissionRoleSeeder::class);

    $permissionsAfterFirstRun = $companyAdmin->fresh()
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    $this->seed(PermissionRoleSeeder::class);

    expect($permissionsAfterFirstRun)
        ->toContain('audit.view', 'reports.export')
        ->not->toContain('backups.run')
        ->and($companyAdmin->fresh()->permissions()->orderBy('name')->pluck('name')->all())
        ->toBe($permissionsAfterFirstRun)
        ->and(Role::findByName('super_admin')->hasPermissionTo('backups.run'))
        ->toBeTrue();
});

test('global backup ability requires both the super admin role and run permission', function () {
    $directlyPermittedUser = User::factory()->create();
    $directlyPermittedUser->givePermissionTo('backups.run');

    $superAdmin = User::factory()->create(['company_id' => null]);
    $superAdmin->assignRole('super_admin');

    expect($directlyPermittedUser->can('backups.run'))->toBeTrue()
        ->and($directlyPermittedUser->can('backups.manage-global'))->toBeFalse()
        ->and($superAdmin->can('backups.manage-global'))->toBeTrue();

    Role::findByName('super_admin')->revokePermissionTo('backups.run');

    expect($superAdmin->fresh()->can('backups.manage-global'))->toBeFalse();
});

test('direct global management permission cannot cross backup boundaries', function () {
    Permission::create([
        'name' => 'backups.manage-global',
        'guard_name' => 'web',
    ]);
    $companyAdmin = User::factory()->forCompany(Company::factory()->create())->create();
    $companyAdmin->assignRole('company_admin');
    $companyAdmin->givePermissionTo('backups.manage-global');
    $navigation = $this->actingAs($companyAdmin)->get(route('profile.change-password'));

    Storage::fake('backups');
    $knownPath = 'sensitive-global-backup.zip';
    Storage::disk('backups')->put($knownPath, 'sensitive-content');
    $companyAdmin->assignRole('super_admin');
    $component = Livewire::actingAs($companyAdmin)->test(Index::class);
    $companyAdmin->removeRole('super_admin');

    expect($companyAdmin->hasPermissionTo('backups.manage-global'))->toBeTrue()
        ->and($companyAdmin->hasPermissionTo('backups.run'))->toBeFalse();

    Artisan::shouldReceive('call')->never();
    config(['filesystems.disks.backups.driver' => 'unavailable']);
    Storage::forgetDisk('backups');

    $navigation->assertOk()->assertDontSee('href="'.route('respaldos.index').'"', escape: false);
    $this->actingAs($companyAdmin)->get(route('respaldos.index'))->assertForbidden();
    $component->call('generate')->assertForbidden();

    foreach ([$knownPath, 'unknown-global-backup.zip'] as $path) {
        $this->get(route('respaldos.download', ['path' => $path]))->assertForbidden();
    }
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

test('company admins from separate companies cannot list global backup metadata', function () {
    Storage::fake('backups');
    $path = 'all-companies-payroll-2026-01-01.zip';
    Storage::disk('backups')->put($path, 'sensitive-archive-content');
    $modified = Storage::disk('backups')->lastModified($path);

    foreach (Company::factory()->count(2)->create() as $company) {
        $admin = User::factory()->forCompany($company)->create();
        $admin->assignRole('company_admin');
        $admin->givePermissionTo('backups.run');

        $this->actingAs($admin)
            ->get(route('respaldos.index'))
            ->assertForbidden()
            ->assertDontSee($path);
    }

    expect(Storage::disk('backups')->allFiles())->toBe([$path])
        ->and(Storage::disk('backups')->get($path))->toBe('sensitive-archive-content')
        ->and(Storage::disk('backups')->lastModified($path))->toBe($modified);
});

test('listing route and component mount deny before backup storage resolution', function () {
    config(['filesystems.disks.backups.driver' => 'unavailable']);

    $user = User::factory()->create();
    $user->givePermissionTo('backups.run');

    $this->actingAs($user)
        ->get(route('respaldos.index'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertForbidden();
});

test('component render reauthorizes before enumerating backup storage', function () {
    Storage::fake('backups');

    $superAdmin = User::factory()->create(['company_id' => null]);
    $superAdmin->assignRole('super_admin');
    $component = Livewire::actingAs($superAdmin)->test(Index::class);

    $superAdmin->removeRole('super_admin');
    $superAdmin->givePermissionTo('backups.run');
    config(['filesystems.disks.backups.driver' => 'unavailable']);
    Storage::forgetDisk('backups');

    $component->call('$refresh')->assertForbidden();
});

test('super admin can list backup zip files', function () {
    Storage::fake('backups');
    Storage::disk('backups')->put('nomina-2026-01-01.zip', 'content');
    Storage::disk('backups')->put('nomina-2026-01-02.zip', 'content');

    $superAdmin = User::factory()->create(['company_id' => null]);
    $superAdmin->assignRole('super_admin');

    Livewire::actingAs($superAdmin)
        ->test(Index::class)
        ->assertSee('nomina-2026-01-01.zip')
        ->assertSee('nomina-2026-01-02.zip');
});

test('restore button remains separately gated by restore permission', function () {
    Storage::fake('backups');

    $superAdmin = User::factory()->create(['company_id' => null]);
    $superAdmin->assignRole('super_admin');
    Role::findByName('super_admin')->revokePermissionTo('backups.restore');

    Livewire::actingAs($superAdmin->fresh())
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

test('denied generation does not call Artisan or mutate backup storage', function () {
    Storage::fake('backups');
    $path = 'existing-global-backup.zip';
    Storage::disk('backups')->put($path, 'original-content');
    $storageBefore = [
        'files' => Storage::disk('backups')->allFiles(),
        'content' => Storage::disk('backups')->get($path),
        'modified' => Storage::disk('backups')->lastModified($path),
    ];
    Artisan::shouldReceive('call')->never();

    $superAdmin = User::factory()->create(['company_id' => null]);
    $superAdmin->assignRole('super_admin');
    $component = Livewire::actingAs($superAdmin)->test(Index::class);

    $superAdmin->removeRole('super_admin');
    $superAdmin->givePermissionTo('backups.run');

    $component->call('generate')->assertForbidden();

    expect([
        'files' => Storage::disk('backups')->allFiles(),
        'content' => Storage::disk('backups')->get($path),
        'modified' => Storage::disk('backups')->lastModified($path),
    ])->toBe($storageBefore);
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

test('known and unknown direct downloads are denied before storage access', function () {
    Storage::fake('backups');
    $disk = Storage::disk('backups');
    $knownPath = 'sensitive-global-backup.zip';
    $disk->put($knownPath, 'sensitive-content');
    $storageBefore = [
        'files' => $disk->allFiles(),
        'content' => $disk->get($knownPath),
        'modified' => $disk->lastModified($knownPath),
    ];

    config(['filesystems.disks.backups.driver' => 'unavailable']);
    Storage::forgetDisk('backups');

    $user = User::factory()->create();
    $user->givePermissionTo('backups.run');

    foreach ([$knownPath, 'unknown-global-backup.zip'] as $path) {
        $this->actingAs($user)
            ->get(route('respaldos.download', ['path' => $path]))
            ->assertForbidden()
            ->assertDontSee($path);
    }

    expect([
        'files' => $disk->allFiles(),
        'content' => $disk->get($knownPath),
        'modified' => $disk->lastModified($knownPath),
    ])->toBe($storageBefore);
});

test('super admin can download a backup file', function () {
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
