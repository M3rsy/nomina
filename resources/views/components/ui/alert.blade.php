@props([
    'variant' => 'brand',
    'title' => null,
])

@php
    $variantClasses = match ($variant) {
        'success' => 'border-success bg-success-subtle text-success-strong',
        'warning' => 'border-warning bg-warning-subtle text-warning-strong',
        'danger' => 'border-danger bg-danger-subtle text-danger-strong',
        default => 'border-brand bg-brand-subtle text-brand-strong',
    };
    $assertive = $variant === 'danger';
@endphp

<div
    role="{{ $assertive ? 'alert' : 'status' }}"
    aria-live="{{ $assertive ? 'assertive' : 'polite' }}"
    aria-atomic="true"
    {{ $attributes->class("rounded-2xl border p-4 text-sm {$variantClasses}") }}
>
    @if ($title)
        <p class="font-bold">{{ $title }}</p>
    @endif
    <div @class(['mt-1' => $title])>{{ $slot }}</div>
</div>
