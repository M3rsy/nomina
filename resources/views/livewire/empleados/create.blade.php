<div class="min-h-screen bg-surface-muted">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
        <header class="border-b border-border pb-6">
            <nav aria-label="Migas de pan" class="text-sm font-semibold text-text-muted">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="/empleados" class="transition hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">Gestión de Personal</a></li>
                    <li aria-hidden="true" class="text-border">/</li>
                    <li aria-current="page" class="text-brand">Alta de colaborador</li>
                </ol>
            </nav>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-text">Registrar Nuevo Empleado</h1>
            <p class="mt-2 max-w-3xl text-sm text-text-muted">Completá la información personal y laboral para incorporar al colaborador a la nómina.</p>
        </header>

        <form wire:submit="save" class="mt-6">
            <div class="grid gap-6 lg:grid-cols-2 lg:items-start">
                <x-ui.card data-employee-create-personal aria-labelledby="personal-card-heading">
                    <x-slot:header>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand">Datos personales</p>
                        <h2 id="personal-card-heading" class="mt-1 text-lg font-bold text-text">Información personal e identidad</h2>
                    </x-slot:header>

                    <x-employees.form-fields :is-super-admin="$isSuperAdmin" :companies="$companies" />
                </x-ui.card>

                <x-ui.card data-employee-create-employment aria-labelledby="employment-heading">
                    <x-slot:header>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand">Relación laboral</p>
                        <h2 id="employment-heading" class="mt-1 text-lg font-bold text-text">Información laboral y compensación</h2>
                        <p class="mt-1 text-sm text-text-muted">Registrá el cargo, la remuneración esperada y la fecha de ingreso.</p>
                    </x-slot:header>

                    <div class="space-y-5">
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
                    </div>
                </x-ui.card>

                <x-ui.card data-employee-create-schedule aria-labelledby="schedule-card-heading" class="lg:col-span-2">
                    <x-slot:header>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand">Horario de trabajo</p>
                        <h2 id="schedule-card-heading" class="mt-1 text-lg font-bold text-text">Asignación de jornada</h2>
                        <p class="mt-1 text-sm text-text-muted">Seleccioná la jornada inicial y la fecha desde la que estará vigente.</p>
                    </x-slot:header>

                    <x-employees.schedule-fields :profiles="$scheduleProfiles" />
                </x-ui.card>

                <x-ui.card data-employee-create-notes aria-labelledby="notes-heading" class="lg:col-span-2">
                    <x-slot:header>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand">Información adicional</p>
                        <h2 id="notes-heading" class="mt-1 text-lg font-bold text-text">Notas y configuración</h2>
                        <p class="mt-1 text-sm text-text-muted">Agregá únicamente observaciones relevantes para la ficha del colaborador.</p>
                    </x-slot:header>

                    <label for="notes" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Notas</span>
                        <textarea id="notes" wire:model="notes" rows="4" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('notes') aria-invalid="true" aria-describedby="notes-error" @enderror></textarea>
                        @error('notes') <p id="notes-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                    </label>
                </x-ui.card>
            </div>

            <div data-employee-create-actions class="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-border bg-surface/95 px-4 py-4 shadow-[0_-8px_24px_rgba(15,23,42,0.08)] backdrop-blur sm:mx-0 sm:rounded-2xl sm:border sm:px-5">
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                    <a href="/empleados" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-text-muted transition hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">Cancelar</a>
                    <x-ui.loading-button type="submit" target="save" loading-label="Guardando…" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">Guardar empleado</x-ui.loading-button>
                </div>
            </div>
        </form>
    </div>
</div>
