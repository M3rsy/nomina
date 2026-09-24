<div class="min-h-screen bg-slate-50/80">
    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
        <header class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Crear registro</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Nuevo empleado</h1>
            <p class="mt-2 text-sm text-slate-600">Completá la ficha del empleado con los datos requeridos para incluirlo en la nómina.</p>
        </header>

        <form wire:submit="save" class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="space-y-6">
                <x-employees.form-fields :is-super-admin="$isSuperAdmin" :companies="$companies" />

                <section aria-labelledby="employment-heading" class="space-y-5 border-t border-slate-100 pt-6">
                    <div>
                        <h2 id="employment-heading" class="text-lg font-bold text-slate-900">Datos laborales</h2>
                        <p class="mt-1 text-sm text-slate-600">Registrá el cargo, la remuneración esperada y la fecha de ingreso.</p>
                    </div>

                    <label for="job_title" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Cargo</span>
                        <input id="job_title" type="text" wire:model="job_title" maxlength="100" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('job_title') aria-invalid="true" aria-describedby="job_title-error" @enderror>
                        @error('job_title') <p id="job_title-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                    </label>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <label for="expected_salary" class="block space-y-1.5">
                            <span class="text-sm font-semibold text-slate-800">Salario esperado</span>
                            <input id="expected_salary" type="number" min="0" step="0.01" inputmode="decimal" wire:model="expected_salary" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('expected_salary') aria-invalid="true" aria-describedby="expected_salary-error" @enderror>
                            @error('expected_salary') <p id="expected_salary-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        </label>

                        <label for="hired_at" class="block space-y-1.5">
                            <span class="text-sm font-semibold text-slate-800">Fecha de contratación</span>
                            <input id="hired_at" type="date" wire:model="hired_at" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('hired_at') aria-invalid="true" aria-describedby="hired_at-error" @enderror>
                            @error('hired_at') <p id="hired_at-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        </label>
                    </div>
                </section>

                <x-employees.schedule-fields :profiles="$scheduleProfiles" />

                <label for="notes" class="block space-y-1.5">
                    <span class="text-sm font-semibold text-slate-800">Notas</span>
                    <textarea id="notes" wire:model="notes" rows="4" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('notes') aria-invalid="true" aria-describedby="notes-error" @enderror></textarea>
                    @error('notes') <p id="notes-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                </label>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <a href="/empleados" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Cancelar</a>
                    <x-ui.loading-button type="submit" target="save" loading-label="Guardando…" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">Guardar empleado</x-ui.loading-button>
                </div>
            </div>
        </form>
    </div>
</div>
