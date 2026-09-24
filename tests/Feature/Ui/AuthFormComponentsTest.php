<?php

use Illuminate\Support\Facades\Blade;

function authComponentXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

test('form field renders a labelled input with related validation feedback', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.form-field
            id="account-email"
            label="Correo electrónico"
            type="email"
            error="El correo es obligatorio."
            wire:model="email"
            autocomplete="username"
            inputmode="email"
            required
            autofocus
        />
    BLADE);

    $xpath = authComponentXPath($html);

    expect($html)->toContain('Correo electrónico', 'El correo es obligatorio.')
        ->and($xpath->query('//label[@for="account-email"]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="account-email" and @type="email" and @autocomplete="username" and @inputmode="email" and @required and @autofocus]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="account-email" and @aria-invalid="true" and @aria-describedby="account-email-error"]')->length)->toBe(1)
        ->and($xpath->query('//p[@id="account-email-error" and @role="alert"]')->length)->toBe(1);
});

test('password field renders its hint error and accessible visibility toggle', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.password-field
            id="new-password"
            label="Nueva contraseña"
            hint="Use al menos 8 caracteres."
            error="La contraseña es obligatoria."
            show-label="Mostrar nueva contraseña"
            hide-label="Ocultar nueva contraseña"
            wire:model="password"
            autocomplete="new-password"
            required
        />
    BLADE);

    $xpath = authComponentXPath($html);
    $input = '//input[@id="new-password" and @type="password" and @autocomplete="new-password" and @required]';
    $toggle = '//button[@type="button" and @aria-controls="new-password"]';

    expect($html)->toContain('Nueva contraseña', 'Use al menos 8 caracteres.', 'La contraseña es obligatoria.')
        ->and($xpath->query('//label[@for="new-password"]')->length)->toBe(1)
        ->and($xpath->query($input.'[@aria-invalid="true" and @aria-describedby="new-password-hint new-password-error"]')->length)->toBe(1)
        ->and($xpath->query($input.'[@*[name()="x-bind:type" and .="showPassword ? \'text\' : \'password\'"]]')->length)->toBe(1)
        ->and($xpath->query($toggle.'[@*[name()="x-on:click" and .="showPassword = ! showPassword"]]')->length)->toBe(1)
        ->and($xpath->query($toggle.'[@*[name()="x-bind:aria-pressed" and .="showPassword"]]')->length)->toBe(1)
        ->and($xpath->query('//p[@id="new-password-hint"]')->length)->toBe(1)
        ->and($xpath->query('//p[@id="new-password-error" and @role="alert"]')->length)->toBe(1)
        ->and($html)->toContain("showPassword ? 'Ocultar nueva contraseña' : 'Mostrar nueva contraseña'");
});

test('password field can relate an input to external feedback', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.password-field
            id="password-confirmation"
            label="Confirmar contraseña"
            described-by="shared-password-error"
            :invalid="true"
            wire:model="password_confirmation"
        />
    BLADE);

    $xpath = authComponentXPath($html);

    expect($xpath->query('//input[@id="password-confirmation" and @aria-invalid="true" and @aria-describedby="shared-password-error"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="password-confirmation-error"]')->length)->toBe(0);
});

test('feedback renders error and success messages with appropriate live semantics', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.feedback id="auth-error" type="error">No se pudo iniciar sesión.</x-ui.feedback>
        <x-ui.feedback id="auth-success" type="success">Enlace enviado.</x-ui.feedback>
    BLADE);

    $xpath = authComponentXPath($html);

    expect($html)->toContain('No se pudo iniciar sesión.', 'Enlace enviado.')
        ->and($xpath->query('//div[@id="auth-error" and @role="alert" and @aria-live="assertive"]')->length)->toBe(1)
        ->and($xpath->query('//div[@id="auth-success" and @role="status" and @aria-live="polite"]')->length)->toBe(1);
});
