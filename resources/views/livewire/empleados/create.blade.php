<div class="min-h-screen bg-slate-50/80">
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <header class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Crear registro</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Nuevo empleado</h1>
                <p class="mt-2 text-sm text-slate-600">Completá la ficha del empleado con los datos requeridos para poder incluirlo en la nómina.</p>
            </div>
        </header>

        <form wire:submit="save" class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="space-y-5">
                @if ($isSuperAdmin)
                    <label for="company_id" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Empresa</span>
                        <select
                            id="company_id"
                            wire:model.live="company_id"
                            class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                        >
                            <option value="">Seleccione...</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                        @error('company_id')
                            <p id="company_id-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>
                @endif

                <div class="grid gap-5 sm:grid-cols-2">
                    <label for="external_id" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Código de empleado</span>
                        <input id="external_id" type="text" wire:model="external_id" required class="h-11 w-full rounded-xl border border-slate-300 px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @error('external_id')
                            <p id="external_id-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>

                    <label for="dni" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Identidad (DNI)</span>
                        <input id="dni" type="text" wire:model="dni" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @error('dni')
                            <p id="dni-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <label for="first_name" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Nombre</span>
                        <input id="first_name" type="text" wire:model="first_name" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @error('first_name')
                            <p id="first_name-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>

                    <label for="last_name" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Apellido</span>
                        <input id="last_name" type="text" wire:model="last_name" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @error('last_name')
                            <p id="last_name-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <label for="sex" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Sexo</span>
                        <select
                            id="sex"
                            wire:model="sex"
                            class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                        >
                            <option value="">Seleccione...</option>
                            <option value="M">Masculino</option>
                            <option value="F">Femenino</option>
                            <option value="O">Otro</option>
                        </select>
                        @error('sex')
                            <p id="sex-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>

                    <label for="birth_date" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Fecha de nacimiento</span>
                        <input id="birth_date" type="date" wire:model="birth_date" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @error('birth_date')
                            <p id="birth_date-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>
                </div>

                <label for="address" class="block space-y-1.5">
                    <span class="text-sm font-semibold text-slate-800">Dirección</span>
                    <input id="address" type="text" wire:model="address" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                    @error('address')
                        <p id="address-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </label>

                <label for="phone" class="block space-y-1.5">
                    <span class="text-sm font-semibold text-slate-800">Teléfono</span>
                    <input id="phone" type="text" wire:model="phone" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                    @error('phone')
                        <p id="phone-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </label>

                <label for="job_title" class="block space-y-1.5">
                    <span class="text-sm font-semibold text-slate-800">Cargo</span>
                    <input id="job_title" type="text" wire:model="job_title" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                    @error('job_title')
                        <p id="job_title-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </label>

                <div class="grid gap-5 sm:grid-cols-2">
                    <label for="expected_salary" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Salario esperado</span>
                        <input id="expected_salary" type="number" step="0.01" wire:model="expected_salary" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @error('expected_salary')
                            <p id="expected_salary-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>

                    <label for="hired_at" class="block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Fecha de contratación</span>
                        <input id="hired_at" type="date" wire:model="hired_at" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @error('hired_at')
                            <p id="hired_at-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </label>
                </div>

                <section class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 sm:p-5">
                    <div class="mb-4">
                        <h2 class="text-base font-bold text-slate-900">Jornada asignada</h2>
                        <p class="mt-1 text-sm text-slate-600">Define qué horario rige para este empleado y desde cuándo.</p>
                    </div>

                    @if ($scheduleProfiles->isEmpty())
                        <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-900">
                            Esta empresa todavía no tiene jornadas disponibles. Configuralas en Jornadas antes de crear el empleado.
                        </p>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label for="schedule_profile_id" class="block space-y-1.5">
                            <span class="text-sm font-semibold text-slate-800">Jornada</span>
                            <select id="schedule_profile_id" wire:model="schedule_profile_id" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                                <option value="">Seleccione...</option>
                                @foreach ($scheduleProfiles as $profile)
                                    <option value="{{ $profile->id }}">{{ $profile->name }} · v{{ $profile->version }}</option>
                                @endforeach
                            </select>
                            @error('schedule_profile_id') <p role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        </label>

                        <label for="schedule_effective_from" class="block space-y-1.5">
                            <span class="text-sm font-semibold text-slate-800">Vigente desde</span>
                            <input id="schedule_effective_from" type="date" wire:model="schedule_effective_from" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                            @error('schedule_effective_from') <p role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        </label>
                    </div>

                    <label for="schedule_reason" class="mt-4 block space-y-1.5">
                        <span class="text-sm font-semibold text-slate-800">Motivo de la asignación</span>
                        <input id="schedule_reason" type="text" wire:model="schedule_reason" maxlength="255" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @error('schedule_reason') <p role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                    </label>
                </section>

                <label for="notes" class="block space-y-1.5">
                    <span class="text-sm font-semibold text-slate-800">Notas</span>
                    <textarea id="notes" wire:model="notes" rows="4" class="w-full rounded-xl border border-slate-300 bg-white p-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"></textarea>
                    @error('notes')
                        <p id="notes-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </label>

                <div class="border-t border-slate-100 pt-3">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                    >
                        <span wire:loading.remove>Guardar</span>
                        <span wire:loading>Guardando...</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
