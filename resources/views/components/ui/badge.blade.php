@props(['variant' => 'neutral'])

@php
    $variantClasses = match ($variant) {
        'brand' => 'bg-brand-subtle text-brand-strong',
        'success' => 'bg-success-subtle text-success-strong',
        'warning' => 'bg-warning-subtle text-warning-strong',
        'danger' => 'bg-danger-subtle text-danger-strong',
        default => 'bg-surface-muted text-text-muted',
    };
@endphp

<span {{ $attributes->class("inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {$variantClasses}") }}>
    {{ $slot }}
</span>
