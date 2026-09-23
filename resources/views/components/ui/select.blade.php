@props([
    'id',
    'label',
    'hint' => null,
    'error' => null,
])

@php
    $describedBy = collect([
        $attributes->get('aria-describedby'),
        $hint ? "{$id}-hint" : null,
        $error ? "{$id}-error" : null,
    ])->filter()->implode(' ');

    $controlAttributes = $attributes->except(['aria-describedby', 'aria-invalid']);
@endphp

<div class="space-y-1.5">
    <label for="{{ $id }}" class="block text-sm font-semibold text-text">{{ $label }}</label>

    @if ($hint)
        <p id="{{ $id }}-hint" class="text-sm text-text-muted">{{ $hint }}</p>
    @endif

    <select
        id="{{ $id }}"
        aria-invalid="{{ $error ? 'true' : 'false' }}"
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $controlAttributes->class('min-h-11 w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted') }}
    >{{ $slot }}</select>

    @if ($error)
        <p id="{{ $id }}-error" role="alert" class="text-sm font-medium text-danger-strong">{{ $error }}</p>
    @endif
</div>
