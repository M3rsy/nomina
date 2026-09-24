<?php

use App\Support\Nomina\PayPeriodStatusPresentation;
use Illuminate\Support\Facades\Blade;

test('button exposes variants disabled semantics and Livewire attribute pass-through', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.button
            variant="danger"
            type="submit"
            disabled
            wire:click="remove"
            wire:loading.attr="disabled"
            wire:target="remove"
        >
            Remove
        </x-ui.button>
    BLADE);

    expect($html)
        ->toContain('<button')
        ->toContain('type="submit"')
        ->toContain('disabled')
        ->toContain('wire:click="remove"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="remove"')
        ->toContain('bg-danger')
        ->toContain('focus-visible:ring-2')
        ->toContain('Remove');
});

test('disabled link button removes navigation and exposes disabled semantics', function () {
    $html = Blade::render('<x-ui.button href="/employees/create" variant="secondary" disabled>Create employee</x-ui.button>');

    expect($html)
        ->toContain('<a')
        ->toContain('aria-disabled="true"')
        ->toContain('tabindex="-1"')
        ->toContain('border-border')
        ->not->toContain('href=');
});

test('badge renders semantic color variants and preserves attributes', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.badge variant="success" data-state="active">Active</x-ui.badge>
    BLADE);

    expect($html)
        ->toContain('data-state="active"')
        ->toContain('bg-success-subtle')
        ->toContain('text-success-strong')
        ->toContain('Active');
});

test('card renders optional header body and footer slots', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.card aria-labelledby="summary-heading">
            <x-slot:header><h2 id="summary-heading">Summary</h2></x-slot:header>
            Card body
            <x-slot:footer>Card actions</x-slot:footer>
        </x-ui.card>
    BLADE);

    expect($html)
        ->toContain('aria-labelledby="summary-heading"')
        ->toContain('Summary')
        ->toContain('Card body')
        ->toContain('Card actions')
        ->toContain('border-border')
        ->toContain('bg-surface');
});

test('alert exposes assertive and polite accessible variants', function () {
    $danger = Blade::render('<x-ui.alert variant="danger" title="Unable to save">Try again.</x-ui.alert>');
    $info = Blade::render('<x-ui.alert variant="brand">Saved draft.</x-ui.alert>');

    expect($danger)
        ->toContain('role="alert"')
        ->toContain('aria-live="assertive"')
        ->toContain('Unable to save')
        ->toContain('Try again.')
        ->toContain('border-danger');

    expect($info)
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('Saved draft.');
});

test('input associates its label hint and validation error', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.input
            id="employee-name"
            label="Employee name"
            hint="Use the legal name."
            error="The employee name is required."
            wire:model="name"
            required
        />
    BLADE);

    expect($html)
        ->toContain('for="employee-name"')
        ->toContain('id="employee-name"')
        ->toContain('wire:model="name"')
        ->toContain('required')
        ->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="employee-name-hint employee-name-error"')
        ->toContain('id="employee-name-error"')
        ->toContain('role="alert"')
        ->toContain('focus-visible:ring-2');
});

test('textarea renders content and accessible error metadata', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.textarea id="notes" label="Notes" error="Notes are too long." rows="5" wire:model="notes">Existing notes</x-ui.textarea>
    BLADE);

    expect($html)
        ->toContain('<textarea')
        ->toContain('for="notes"')
        ->toContain('rows="5"')
        ->toContain('wire:model="notes"')
        ->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="notes-error"')
        ->toContain('Existing notes')
        ->toContain('role="alert"');
});

test('select associates its option slot and validation metadata', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.select id="status" label="Status" hint="Choose one." wire:model.live="status">
            <option value="active">Active</option>
        </x-ui.select>
    BLADE);

    expect($html)
        ->toContain('<select')
        ->toContain('for="status"')
        ->toContain('wire:model.live="status"')
        ->toContain('aria-invalid="false"')
        ->toContain('aria-describedby="status-hint"')
        ->toContain('<option value="active">Active</option>');
});

test('dashboard components expose reusable hierarchy and empty-state semantics', function () {
    $header = Blade::render(<<<'BLADE'
        <x-ui.page-header title="Payroll overview" description="Current payroll health.">
            <x-slot:actions>Header action</x-slot:actions>
        </x-ui.page-header>
    BLADE);
    $stat = Blade::render('<x-ui.stat-card label="Active employees" value="24" tone="success">Ready for payroll.</x-ui.stat-card>');
    $empty = Blade::render('<x-ui.empty-state title="No payroll periods">Adjust the selected dates.</x-ui.empty-state>');

    expect($header)
        ->toContain('Payroll overview')
        ->toContain('Current payroll health.')
        ->toContain('Header action')
        ->toContain('border-border');

    expect($stat)
        ->toContain('Active employees')
        ->toContain('24')
        ->toContain('Ready for payroll.')
        ->toContain('text-success-strong');

    expect($empty)
        ->toContain('role="status"')
        ->toContain('No payroll periods')
        ->toContain('Adjust the selected dates.')
        ->toContain('border-dashed');
});

test('payroll workflow presents five descriptive phases and the current stored status', function () {
    $presentation = PayPeriodStatusPresentation::for('validating');
    $phases = PayPeriodStatusPresentation::phases();

    $html = Blade::render(
        '<x-nomina.payroll-workflow :phases="$phases" :presentation="$presentation" />',
        compact('phases', 'presentation'),
    );

    expect($html)
        ->toContain('aria-label="Flujo de nómina"')
        ->toContain('aria-current="step"')
        ->toContain('Estado actual: Validando. Fase actual: Revisión.')
        ->toContain('Período')
        ->toContain('Carga')
        ->toContain('Revisión')
        ->toContain('Proceso')
        ->toContain('Aprobación y exportación')
        ->not->toContain('<a')
        ->not->toContain('<button');
});

test('payroll workflow leaves every phase inactive for an unknown status', function () {
    $presentation = PayPeriodStatusPresentation::for('future_state');
    $phases = PayPeriodStatusPresentation::phases();

    $html = Blade::render(
        '<x-nomina.payroll-workflow :phases="$phases" :presentation="$presentation" />',
        compact('phases', 'presentation'),
    );

    expect($html)
        ->toContain('Estado actual: Estado desconocido. Fase actual: ninguna.')
        ->not->toContain('aria-current="step"');
});

test('Tailwind theme exposes the semantic design token families', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('--color-brand:')
        ->toContain('--color-surface:')
        ->toContain('--color-border:')
        ->toContain('--color-text:')
        ->toContain('--color-success:')
        ->toContain('--color-warning:')
        ->toContain('--color-danger:');
});
