@props(['title'])

<div role="status" {{ $attributes->class('rounded-2xl border border-dashed border-border bg-surface-muted px-5 py-6 text-center') }}>
    <p class="font-semibold text-text">{{ $title }}</p>
    <div class="mx-auto mt-1 max-w-xl text-sm leading-6 text-text-muted">{{ $slot }}</div>
    @isset($actions)
        <div class="mt-4 flex flex-wrap justify-center gap-3">{{ $actions }}</div>
    @endisset
</div>
