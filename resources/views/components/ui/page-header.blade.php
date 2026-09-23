@props([
    'title',
    'description' => null,
])

<header {{ $attributes->class('relative overflow-hidden rounded-3xl border border-border bg-surface px-5 py-6 shadow-sm sm:px-7') }}>
    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand via-brand-strong to-success" aria-hidden="true"></div>
    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-text sm:text-3xl">{{ $title }}</h1>
            @if ($description)
                <p class="mt-2 max-w-3xl text-sm leading-6 text-text-muted">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex shrink-0 flex-wrap gap-3">{{ $actions }}</div>
        @endisset
    </div>
</header>
