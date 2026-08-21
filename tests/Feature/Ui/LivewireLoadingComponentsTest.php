<?php

use Illuminate\Support\Facades\Blade;

test('loading button scopes accessible feedback to its action', function () {
    expect(view()->exists('components.ui.loading-button'))->toBeTrue();

    $html = Blade::render(<<<'BLADE'
        <x-ui.loading-button
            id="save-action"
            target="save"
            loading-label="Guardando…"
            wire:click="save"
            class="bg-indigo-600"
        >
            Guardar
        </x-ui.loading-button>
    BLADE);

    expect($html)
        ->toContain('<button')
        ->toContain('id="save-action"')
        ->toContain('wire:click="save"')
        ->toContain('wire:target="save"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:loading.attr="aria-busy"')
        ->toContain('wire:loading.remove')
        ->toContain('Guardando…')
        ->toContain('animate-spin')
        ->toContain('motion-reduce:animate-none')
        ->toContain('aria-hidden="true"');
});

test('loading overlay is delayed accessible and scoped to blocking actions', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.loading-overlay
            target="validate,process"
            message="Validando y procesando…"
        />
    BLADE);

    expect($html)
        ->toContain('wire:loading.delay.short')
        ->toContain('wire:target="validate,process"')
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('aria-atomic="true"')
        ->toContain('Validando y procesando…')
        ->toContain('motion-reduce:animate-none');
});
