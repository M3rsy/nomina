@props([
    'target',
    'loadingLabel',
])

<button
    {{ $attributes->merge(['type' => 'button'])->class('disabled:cursor-not-allowed disabled:opacity-50') }}
    wire:target="{{ $target }}"
    wire:loading.attr="disabled"
    wire:loading.attr="aria-busy"
>
    <span wire:loading.remove wire:target="{{ $target }}">{{ $slot }}</span>
    <span wire:loading.flex wire:target="{{ $target }}" class="items-center gap-2">
        <svg class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
            <path class="opacity-75" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3Z" />
        </svg>
        <span>{{ $loadingLabel }}</span>
    </span>
    <span role="status" aria-live="polite" class="sr-only">
        <span wire:loading wire:target="{{ $target }}">{{ $loadingLabel }}</span>
    </span>
</button>
