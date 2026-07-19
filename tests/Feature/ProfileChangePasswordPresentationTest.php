<?php

use App\Livewire\Profile\ChangePassword;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

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

    $response = $this->withSession(['status' => 'Contraseña actualizada correctamente.'])
        ->actingAs($user)
        ->get(route('profile.change-password'));

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

    $response = $this->actingAs($user)->get(route('profile.change-password'));

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
