<?php

use App\Livewire\Profile\ChangePassword;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('change password validation errors are announced and related to affected fields', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $component = Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->call('save')
        ->assertHasErrors(['current_password', 'password']);

    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//input[@id="current-password" and @aria-invalid="true" and @aria-describedby="current-password-error"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="current-password-error" and @role="alert"]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="new-password" and @aria-invalid="true" and contains(concat(" ", normalize-space(@aria-describedby), " "), " new-password-error ")]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="new-password-confirmation" and @aria-invalid="true" and contains(concat(" ", normalize-space(@aria-describedby), " "), " new-password-error ")]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="new-password-error" and @role="alert"]')->length)->toBe(1);
});

test('change password success feedback is announced as status', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    actingAs($user);
    session()->flash('status', 'Contraseña actualizada correctamente.');

    $response = get(route('profile.change-password'));

    $response->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $statuses = (new DOMXPath($document))->query(
        '//*[@id="change-password-status" and @role="status" and @aria-live="polite"'
        .' and normalize-space()="Contraseña actualizada correctamente."]',
    );

    expect($statuses->length)->toBe(1);
});

test('change password fields and submission expose autocomplete and loading feedback', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = actingAs($user)->get(route('profile.change-password'));

    $response->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $button = '//button[@type="submit" and @*[name()="wire:loading.attr"]="disabled" and @*[name()="wire:target"]="save"]';

    expect($xpath->query('//input[@id="current-password" and @autocomplete="current-password" and @aria-invalid="false"]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="new-password" and @autocomplete="new-password" and @aria-invalid="false"]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="new-password-confirmation" and @autocomplete="new-password" and @aria-invalid="false"]')->length)->toBe(1)
        ->and($xpath->query($button)->length)->toBe(1)
        ->and($xpath->query(
            $button.'/following-sibling::*[1][@role="status" and @aria-live="polite"]'
            .'/*[@*[name()="wire:loading"] and @*[name()="wire:target"]="save" and normalize-space()="Guardando contraseña..."]',
        )->length)->toBe(1);
});

test('change password page presents truthful account context and security guidance', function () {
    $user = User::factory()->create([
        'name' => 'Andrea López',
        'email' => 'andrea@example.test',
    ]);
    $user->assignRole('super_admin');

    $response = actingAs($user)->get(route('profile.change-password'));

    $response->assertOk()
        ->assertSeeText('Seguridad de la cuenta')
        ->assertSeeText('Cambiar contraseña')
        ->assertSeeText('Andrea López')
        ->assertSeeText('andrea@example.test')
        ->assertSeeText('Superadministrador')
        ->assertSeeText('Acceso global')
        ->assertSeeText('Requisitos obligatorios')
        ->assertSeeText('Al menos 8 caracteres')
        ->assertSeeText('Diferente de la contraseña actual')
        ->assertSeeText('La confirmación coincide')
        ->assertSeeText('Opcional')
        ->assertSeeText('Estimación de fortaleza')
        ->assertSeeText('hash seguro')
        ->assertSeeText('invalida las demás sesiones guardadas');

    $content = mb_strtolower($response->getContent());

    expect($content)->not->toContain('2fa')
        ->not->toContain('totp')
        ->not->toContain('94/100')
        ->not->toContain('historial de contraseñas')
        ->not->toContain('argon2')
        ->not->toContain('sha-')
        ->not->toContain('rgpd')
        ->not->toContain('ssl-256')
        ->not->toContain('olvidé mi contraseña');
});

test('change password visibility controls and local guidance remain accessible and non-blocking', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = actingAs($user)->get(route('profile.change-password'));

    $response->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);

    foreach (['current-password', 'new-password', 'new-password-confirmation'] as $fieldId) {
        expect($xpath->query(
            '//button[@type="button" and @aria-controls="'.$fieldId.'"'
            .' and @*[name()="x-bind:aria-label"] and @*[name()="x-bind:aria-pressed"]]',
        )->length)->toBe(1);
    }

    expect($xpath->query('//*[contains(@x-data, "currentPassword")]')->length)->toBe(1)
        ->and($xpath->query('//form[@*[name()="wire:submit"]="save"]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="current-password" and @*[name()="wire:model"]="current_password" and @x-model="currentPassword"]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="new-password" and @*[name()="wire:model"]="password" and @x-model="newPassword"]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="new-password-confirmation" and @*[name()="wire:model"]="password_confirmation" and @x-model="confirmation"]')->length)->toBe(1)
        ->and($xpath->query('//a[@href="'.route('dashboard').'" and normalize-space()="Cancelar"]')->length)->toBe(1)
        ->and($response->getContent())->toContain('return this.currentPassword.length > 0 && this.newPassword.length > 0')
        ->and($response->getContent())->toContain('return this.newPassword.length > 0 && this.confirmation.length > 0');
});

test('change password page shows the assigned company for company administrators', function () {
    $company = Company::factory()->create(['name' => 'Empresa Real']);
    $user = User::factory()->forCompany($company)->create([
        'name' => 'Carlos Mejía',
        'email' => 'carlos@example.test',
    ]);
    $user->assignRole('company_admin');

    actingAs($user)->get(route('profile.change-password'))
        ->assertOk()
        ->assertSeeText('Administrador de empresa')
        ->assertSeeText('Empresa Real')
        ->assertDontSeeText('Acceso global');
});
