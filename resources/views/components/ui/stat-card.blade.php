@props([
    'label',
    'value',
    'tone' => 'brand',
])

@php
    $toneClasses = match ($tone) {
        'success' => 'border-success/30 text-success-strong',
        'warning' => 'border-warning/40 text-warning-strong',
        'danger' => 'border-danger/30 text-danger-strong',
        'neutral' => 'border-border text-text',
        default => 'border-brand/30 text-brand-strong',
    };
@endphp

<article {{ $attributes->class("rounded-3xl border bg-surface p-5 shadow-sm {$toneClasses}") }}>
    <p class="text-sm font-semibold text-text-muted">{{ $label }}</p>
    <p class="mt-2 text-3xl font-bold tracking-tight">{{ $value }}</p>
    @if (trim((string) $slot) !== '')
        <div class="mt-2 text-xs leading-5 text-text-muted">{{ $slot }}</div>
    @endif
</article>
