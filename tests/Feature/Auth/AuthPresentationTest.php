<?php

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

function authPresentationXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

test('login presents the payroll operations identity without authenticated navigation', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Centro operativo de nómina')
        ->assertSee('Asistencia y trazabilidad en cada jornada.')
        ->assertSee('06-14')
        ->assertSee('14-18')
        ->assertSee('18-00')
        ->assertSee('00-06')
        ->assertSee('Desarrollado por CFV Technology')
        ->assertDontSee('Credenciales demo')
        ->assertDontSee('aria-label="Navegación principal"', escape: false);
});

test('login exposes accessible fields password toggle and loading feedback', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('wire:submit="login"', escape: false)
        ->assertSee('id="login-email"', escape: false)
        ->assertSee('autocomplete="username"', escape: false)
        ->assertDontSee('autocomplete="email"', escape: false)
        ->assertSee('inputmode="email"', escape: false)
        ->assertSee('autofocus', escape: false)
        ->assertSee('id="login-password"', escape: false)
        ->assertSee('autocomplete="current-password"', escape: false)
        ->assertSee('x-bind:type="showPassword ? \'text\' : \'password\'"', escape: false)
        ->assertSee('x-on:click="showPassword = ! showPassword"', escape: false)
        ->assertSee('x-bind:aria-pressed="showPassword"', escape: false)
        ->assertSee('aria-controls="login-password"', escape: false)
        ->assertSee("showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'", escape: false)
        ->assertSee('wire:loading.attr="disabled"', escape: false)
        ->assertSee('wire:target="login"', escape: false)
        ->assertSee('role="status"', escape: false)
        ->assertSee('href="'.route('password.request').'"', escape: false);
});

test('auth loading feedback uses one live region after each submit button', function () {
    $cases = [
        [route('login'), 'login', 'Ingresando...'],
        [route('password.request'), 'sendResetLink', 'Enviando enlace...'],
        [route('password.reset', ['token' => 'presentation-token']), 'resetPassword', 'Restableciendo...'],
    ];

    foreach ($cases as [$route, $target, $message]) {
        $xpath = authPresentationXPath($this->get($route)->assertOk()->getContent());
        $button = '//button[@type="submit" and @*[name()="wire:target"]="'.$target.'"]';

        expect($xpath->query($button.'//*[@role="status" or @aria-live]')->length)->toBe(0)
            ->and($xpath->query(
                $button.'/following-sibling::*[1][@role="status" and @aria-live="polite"]'
                .'/*[@*[name()="wire:loading"] and @*[name()="wire:target"]="'.$target.'" and normalize-space()="'.$message.'"]',
            )->length)->toBe(1);
    }
});

test('login validation errors are announced and related to their fields', function () {
    Livewire::test(Login::class)
        ->call('login')
        ->assertHasErrors(['email', 'password'])
        ->assertSeeHtml('aria-describedby="login-email-error"')
        ->assertSeeHtml('id="login-email-error" role="alert"')
        ->assertSeeHtml('aria-describedby="login-password-error"')
        ->assertSeeHtml('id="login-password-error" role="alert"');
});

test('forgot password reuses the identity with accessible email and loading feedback', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('data-auth-shell', escape: false)
        ->assertSee('Centro operativo de nómina')
        ->assertSee('Desarrollado por CFV Technology')
        ->assertSee('wire:submit="sendResetLink"', escape: false)
        ->assertSee('id="forgot-email"', escape: false)
        ->assertSee('autocomplete="email"', escape: false)
        ->assertDontSee('autocomplete="username"', escape: false)
        ->assertSee('inputmode="email"', escape: false)
        ->assertSee('autofocus', escape: false)
        ->assertSee('wire:loading.attr="disabled"', escape: false)
        ->assertSee('wire:target="sendResetLink"', escape: false)
        ->assertSee('role="status"', escape: false)
        ->assertSee('href="'.route('login').'"', escape: false);
});

test('forgot password announces validation errors and successful feedback', function () {
    Livewire::test(ForgotPassword::class)
        ->call('sendResetLink')
        ->assertHasErrors('email')
        ->assertSeeHtml('aria-describedby="forgot-email-error"')
        ->assertSeeHtml('id="forgot-email-error" role="alert"');

    Livewire::test(ForgotPassword::class)
        ->set('status', 'Enlace enviado.')
        ->assertSeeHtml('id="forgot-status" role="status" aria-live="polite"');
});

test('forgot password presents localized success feedback and clears the email', function () {
    $email = 'persona@empresa.test';

    Password::expects('sendResetLink')
        ->once()
        ->with(['email' => $email])
        ->andReturn(Password::RESET_LINK_SENT);

    Livewire::test(ForgotPassword::class)
        ->set('email', $email)
        ->call('sendResetLink')
        ->assertSet('status', 'Le enviamos un enlace para restablecer su contraseña.')
        ->assertSet('email', '')
        ->assertSee('Le enviamos un enlace para restablecer su contraseña.')
        ->assertSeeHtml('id="forgot-status" role="status" aria-live="polite"');
});

test('reset password reuses the identity with new password controls and loading feedback', function () {
    $response = $this->get(route('password.reset', ['token' => 'presentation-token']));

    $response
        ->assertOk()
        ->assertSee('data-auth-shell', escape: false)
        ->assertSee('Centro operativo de nómina')
        ->assertSee('Desarrollado por CFV Technology')
        ->assertSee('wire:submit="resetPassword"', escape: false)
        ->assertSee('id="reset-email"', escape: false)
        ->assertSee('autocomplete="email"', escape: false)
        ->assertDontSee('autocomplete="username"', escape: false)
        ->assertSee('inputmode="email"', escape: false)
        ->assertSee('id="reset-password"', escape: false)
        ->assertSee('id="reset-password-confirmation"', escape: false)
        ->assertSee('autocomplete="new-password"', escape: false)
        ->assertSee('aria-controls="reset-password"', escape: false)
        ->assertSee('aria-controls="reset-password-confirmation"', escape: false)
        ->assertSee("showPassword ? 'Ocultar nueva contraseña' : 'Mostrar nueva contraseña'", escape: false)
        ->assertSee("showPassword ? 'Ocultar confirmación de contraseña' : 'Mostrar confirmación de contraseña'", escape: false)
        ->assertSee('wire:loading.attr="disabled"', escape: false)
        ->assertSee('wire:target="resetPassword"', escape: false)
        ->assertSee('role="status"', escape: false)
        ->assertSee('href="'.route('login').'"', escape: false);

    expect(substr_count($response->getContent(), 'autocomplete="new-password"'))->toBe(2)
        ->and(substr_count($response->getContent(), 'x-bind:type="showPassword ? \'text\' : \'password\'"'))->toBe(2)
        ->and(substr_count($response->getContent(), 'x-on:click="showPassword = ! showPassword"'))->toBe(2)
        ->and(substr_count($response->getContent(), 'x-bind:aria-pressed="showPassword"'))->toBe(2);
});

test('reset password validation errors are related to affected fields', function () {
    Livewire::test(ResetPassword::class, ['token' => 'presentation-token'])
        ->call('resetPassword')
        ->assertHasErrors(['email', 'password'])
        ->assertSeeHtml('aria-describedby="reset-email-error"')
        ->assertSeeHtml('id="reset-email-error" role="alert"')
        ->assertSeeHtml('reset-password-error')
        ->assertSeeHtml('id="reset-password-error" role="alert"');
});
