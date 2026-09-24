@props(['assignments'])

<div data-employee-schedule-history class="mb-4">
    <h3 class="text-sm font-bold text-slate-900">Historial de jornadas</h3>
    @if ($assignments->isNotEmpty())
        <ol class="mt-3 space-y-2">
            @foreach ($assignments as $assignment)
                <li class="rounded-xl border border-indigo-100 bg-white px-3 py-3 text-sm text-slate-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold text-slate-900">{{ $assignment->profile?->name ?? 'Jornada no disponible' }}</span>
                        @if ($assignment->effective_to === null)
                            <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-bold text-indigo-800">Actual</span>
                        @endif
                    </div>
                    <p class="mt-1">
                        <time datetime="{{ $assignment->effective_from->toDateString() }}">{{ $assignment->effective_from->format('d/m/Y') }}</time>–@if ($assignment->effective_to)<time datetime="{{ $assignment->effective_to->toDateString() }}">{{ $assignment->effective_to->format('d/m/Y') }}</time>@else actualidad @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500">{{ $assignment->reason }}</p>
                </li>
            @endforeach
        </ol>
    @else
        <p role="status" class="mt-3 rounded-xl border border-dashed border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">Este empleado todavía no tiene una jornada asignada.</p>
    @endif
</div>
