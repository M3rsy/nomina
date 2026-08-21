@props([
    'target',
    'message',
])

<div
    wire:loading.delay.short.flex
    wire:target="{{ $target }}"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    class="absolute inset-0 z-40 items-center justify-center rounded-[inherit] bg-white/85 p-6 backdrop-blur-sm"
>
    <div class="flex max-w-sm items-center gap-3 rounded-2xl border border-indigo-100 bg-white px-5 py-4 text-sm font-semibold text-slate-800 shadow-lg">
        <svg class="size-5 shrink-0 animate-spin text-indigo-600 motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
            <path class="opacity-75" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3Z" />
        </svg>
        <span>{{ $message }}</span>
    </div>
</div>
