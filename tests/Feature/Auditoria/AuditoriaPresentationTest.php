<?php

use App\Livewire\Auditoria\Index;
use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('audit workspace presents its hierarchy and live data as read only', function () {
    $company = Company::factory()->create(['name' => 'Acme Audit Company']);
    $admin = User::factory()->forCompany($company)->create([
        'email' => 'audit.admin@example.test',
    ])->assignRole('company_admin');

    LoginAttempt::factory()->count(3)->create([
        'company_id' => $company->id,
        'email' => $admin->email,
        'success' => true,
    ]);

    $component = Livewire::actingAs($admin)->test(Index::class)
        ->assertSeeHtml('data-audit-section="hero"')
        ->assertSeeHtml('data-audit-section="summary"')
        ->assertSeeHtml('data-audit-section="filters"')
        ->assertSeeHtml('data-audit-section="records"')
        ->assertSee('Auditoría del sistema')
        ->assertSee('Seguridad y trazabilidad')
        ->assertSee('Bitácora de eventos auditados')
        ->assertSee('Eventos encontrados')
        ->assertSee('3 registros disponibles para este contexto.')
        ->assertSee('Historial completo')
        ->assertSee('Registro de solo lectura')
        ->assertSeeHtml('wire:model.live="type"')
        ->assertSeeHtml('wire:model.live.debounce.300ms="user"')
        ->assertSeeHtml('wire:model.live="from"')
        ->assertSeeHtml('wire:model.live="to"')
        ->assertDontSeeHtml('wire:submit=')
        ->assertDontSeeHtml('wire:click=');

    expect($component->viewData('entries')->total())->toBe(3)
        ->and(AuditLogEntry::query()->count())->toBe(3);
});

test('audit summary and filter indicators reflect the real filtered feed', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    LoginAttempt::factory()->create([
        'company_id' => $company->id,
        'email' => 'selected.auditor@example.test',
        'success' => true,
        'created_at' => now()->subDay(),
    ]);
    LoginAttempt::factory()->create([
        'company_id' => $company->id,
        'email' => 'other.auditor@example.test',
        'success' => true,
        'created_at' => now()->subDay(),
    ]);
    LoginAttempt::factory()->create([
        'company_id' => $company->id,
        'email' => 'selected.auditor@example.test',
        'success' => true,
        'created_at' => now()->subDays(30),
    ]);

    Livewire::actingAs($admin)->test(Index::class)
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 3)
        ->set('type', 'login_attempt')
        ->set('user', 'selected.auditor')
        ->set('from', now()->subDays(10)->format('Y-m-d'))
        ->set('to', now()->format('Y-m-d'))
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 1)
        ->assertSee('Filtrada')
        ->assertSee('1 registros disponibles para este contexto.')
        ->assertSee('Tipo:')
        ->assertSee('Intentos de inicio de sesión')
        ->assertSee('Usuario:')
        ->assertSee('selected.auditor')
        ->assertSee('Desde:')
        ->assertSee(now()->subDays(10)->format('Y-m-d'))
        ->assertSee('Hasta:')
        ->assertSee(now()->format('Y-m-d'))
        ->assertSee('selected.auditor@example.test')
        ->assertDontSee('other.auditor@example.test');

    expect(LoginAttempt::query()->count())->toBe(3)
        ->and(AuditLogEntry::query()->count())->toBe(3);
});

test('audit presentation remains protected by the audit view permission', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);

    get(route('auditoria.index'))->assertRedirect('/login');
    actingAs($user);
    get(route('auditoria.index'))->assertForbidden();
});
