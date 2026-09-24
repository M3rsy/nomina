@props([
    'phases',
    'presentation' => null,
])

@php
    $activeIndex = $presentation?->phaseIndex;
    $currentPhase = collect($phases)->firstWhere('index', $activeIndex);
@endphp

<nav aria-label="Flujo de nómina" {{ $attributes }}>
    <p class="sr-only">
        @if ($presentation)
            Estado actual: {{ $presentation->label }}. Fase actual: {{ $currentPhase['label'] ?? 'ninguna' }}.
        @else
            Flujo general de nómina en cinco fases.
        @endif
    </p>
    <ol class="grid gap-2 sm:grid-cols-5">
        @foreach ($phases as $phase)
            @php
                $isCurrent = $activeIndex === $phase['index'];
                $isCompleted = $presentation?->status !== 'cancelled'
                    && $activeIndex !== null
                    && $phase['index'] < $activeIndex;
            @endphp
            <li
                @if ($isCurrent) aria-current="step" @endif
                @class([
                    'rounded-xl border px-3 py-3 text-sm',
                    'border-brand bg-brand-subtle text-brand-strong' => $isCurrent,
                    'border-success bg-success-subtle text-success-strong' => $isCompleted,
                    'border-border bg-surface-muted text-text-muted' => ! $isCurrent && ! $isCompleted,
                ])
            >
                <span class="block text-xs font-semibold uppercase tracking-wide">
                    Fase {{ $phase['index'] }}@if ($isCompleted), completada @endif
                </span>
                <span class="mt-1 block font-semibold">{{ $phase['label'] }}</span>
            </li>
        @endforeach
    </ol>
</nav>
