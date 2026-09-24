@props([
    'variant' => 'primary',
    'href' => null,
    'disabled' => false,
])

@php
    $variantClasses = match ($variant) {
        'secondary' => 'border border-border bg-surface text-text hover:bg-surface-muted',
        'danger' => 'bg-danger text-white hover:bg-danger-strong',
        default => 'bg-brand text-white hover:bg-brand-strong',
    };

    $classes = "inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 {$variantClasses}";
@endphp

@if ($href)
    <a
        @unless ($disabled) href="{{ $href }}" @endunless
        @if ($disabled) aria-disabled="true" tabindex="-1" @endif
        {{ $attributes->class($classes) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        @disabled($disabled)
        {{ $attributes->merge(['type' => 'button'])->class($classes) }}
    >
        {{ $slot }}
    </button>
@endif
