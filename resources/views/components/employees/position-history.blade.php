@props(['employee', 'assignments'])

<section data-employee-position-history aria-labelledby="position-history-heading" class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 id="position-history-heading" class="text-base font-bold text-slate-900">Historial de cargos</h2>
            <p class="mt-1 text-sm text-slate-600">Cada cambio conserva el cargo anterior y su período de vigencia.</p>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-white px-4 py-3 sm:min-w-56">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Cargo actual</p>
            <p class="mt-1 font-bold text-slate-900">{{ $employee->job_title ?: 'Sin cargo asignado' }}</p>
        </div>
    </div>

    <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <label for="job_title" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Nuevo cargo</span>
            <input id="job_title" type="text" wire:model="job_title" maxlength="100" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30" @error('job_title') aria-invalid="true" aria-describedby="job_title-error" @enderror>
            @error('job_title') <p id="job_title-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>

        <label for="position_effective_from" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Vigente desde</span>
            <input id="position_effective_from" type="date" wire:model="position_effective_from" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30" @error('position_effective_from') aria-invalid="true" aria-describedby="position_effective_from-error" @enderror>
            @error('position_effective_from') <p id="position_effective_from-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>
    </div>

    <label for="position_reason" class="mt-4 block space-y-1.5">
        <span class="text-sm font-semibold text-slate-800">Motivo del cambio</span>
        <input id="position_reason" type="text" wire:model="position_reason" maxlength="255" placeholder="Ej. Promoción interna" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30" @error('position_reason') aria-invalid="true" aria-describedby="position_reason-error" @enderror>
        @error('position_reason') <p id="position_reason-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
    </label>

    <div class="mt-5 border-t border-emerald-100 pt-4">
        <h3 class="text-sm font-bold text-slate-900">Línea de tiempo</h3>
        @if ($assignments->isNotEmpty())
            <ol class="mt-3 space-y-3">
                @foreach ($assignments as $assignment)
                    <li class="rounded-xl border border-emerald-100 bg-white px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-slate-900">{{ $assignment->title }}</span>
                            @if ($assignment->effective_from->gt(now()->startOfDay()))
                                <span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800">Programado</span>
                            @elseif ($assignment->effective_to === null || $assignment->effective_to->gte(now()->startOfDay()))
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">Actual</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-slate-600">
                            <time datetime="{{ $assignment->effective_from->toDateString() }}">{{ $assignment->effective_from->format('d/m/Y') }}</time>–@if ($assignment->effective_to)<time datetime="{{ $assignment->effective_to->toDateString() }}">{{ $assignment->effective_to->format('d/m/Y') }}</time>@else{{ $assignment->effective_from->gt(now()->startOfDay()) ? 'en adelante' : 'actualidad' }}@endif
                        </p>
                        <p class="mt-1 text-xs text-slate-500">{{ $assignment->reason }}</p>
                    </li>
                @endforeach
            </ol>
        @else
            <p role="status" class="mt-3 rounded-xl border border-dashed border-emerald-200 bg-white/70 px-4 py-3 text-sm text-slate-600">Todavía no hay movimientos de cargo registrados.</p>
        @endif
    </div>
</section>
