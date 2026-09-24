@props([
    'profiles',
    'assignments' => null,
    'editing' => false,
])

<section data-employee-schedule-fields aria-labelledby="schedule-heading" class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 sm:p-5">
    <div class="mb-4">
        <h2 id="schedule-heading" class="text-base font-bold text-slate-900">Jornada asignada</h2>
        <p class="mt-1 text-sm text-slate-600">{{ $editing ? 'Una nueva asignación conserva el historial y comienza en la fecha indicada.' : 'Define qué horario rige para este empleado y desde cuándo.' }}</p>
    </div>

    @if ($editing)
        <x-employees.schedule-history :assignments="$assignments" />
    @elseif ($profiles->isEmpty())
        <p role="status" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-900">Esta empresa todavía no tiene jornadas disponibles. Configuralas en Jornadas antes de crear el empleado.</p>
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <label for="schedule_profile_id" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">{{ $editing ? 'Nueva jornada' : 'Jornada' }} <span aria-hidden="true">*</span></span>
            <select id="schedule_profile_id" wire:model="schedule_profile_id" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('schedule_profile_id') aria-invalid="true" aria-describedby="schedule_profile_id-error" @enderror>
                <option value="">{{ $editing ? 'Mantener la jornada actual' : 'Seleccione...' }}</option>
                @foreach ($profiles as $profile)
                    <option value="{{ $profile->id }}">{{ $profile->name }} · v{{ $profile->version }}</option>
                @endforeach
            </select>
            @error('schedule_profile_id') <p id="schedule_profile_id-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>

        <label for="schedule_effective_from" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Vigente desde <span aria-hidden="true">*</span></span>
            <input id="schedule_effective_from" type="date" wire:model="schedule_effective_from" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('schedule_effective_from') aria-invalid="true" aria-describedby="schedule_effective_from-error" @enderror>
            @error('schedule_effective_from') <p id="schedule_effective_from-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>
    </div>

    <label for="schedule_reason" class="mt-4 block space-y-1.5">
        <span class="text-sm font-semibold text-slate-800">{{ $editing ? 'Motivo del cambio' : 'Motivo de la asignación' }} <span aria-hidden="true">*</span></span>
        <input id="schedule_reason" type="text" wire:model="schedule_reason" maxlength="255" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('schedule_reason') aria-invalid="true" aria-describedby="schedule_reason-error" @enderror>
        @error('schedule_reason') <p id="schedule_reason-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
    </label>
</section>
