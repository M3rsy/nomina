@props([
    'isSuperAdmin' => false,
    'companies' => null,
    'employee' => null,
])

<section data-employee-common-fields aria-labelledby="employee-details-heading" class="space-y-5">
    <div>
        <h2 id="employee-details-heading" class="text-lg font-bold text-slate-900">Datos del empleado</h2>
        <p class="mt-1 text-sm text-slate-600">Los campos marcados con <span aria-hidden="true">*</span><span class="sr-only">asterisco</span> son obligatorios.</p>
    </div>

    @if ($isSuperAdmin)
        @if ($employee)
            <div class="space-y-1.5">
                <span class="text-sm font-semibold text-slate-800">Empresa</span>
                <p class="flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-medium text-slate-700">{{ $employee->company->name }}</p>
                <p class="text-xs text-slate-500">La empresa forma parte del historial del empleado y no puede cambiarse desde esta edición.</p>
                @error('company_id') <p id="company_id-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
            </div>
        @else
            <label for="company_id" class="block space-y-1.5">
                <span class="text-sm font-semibold text-slate-800">Empresa <span aria-hidden="true">*</span></span>
                <select id="company_id" wire:model.live="company_id" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('company_id') aria-invalid="true" aria-describedby="company_id-error" @enderror>
                    <option value="">Seleccione...</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
                @error('company_id') <p id="company_id-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
            </label>
        @endif
    @endif

    <div class="grid gap-5 sm:grid-cols-2">
        <label for="external_id" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Código de empleado <span aria-hidden="true">*</span></span>
            <input id="external_id" type="text" wire:model="external_id" required autocomplete="off" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('external_id') aria-invalid="true" aria-describedby="external_id-error" @enderror>
            @error('external_id') <p id="external_id-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>

        <label for="payment_code" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Clave</span>
            <input id="payment_code" type="text" wire:model="payment_code" maxlength="50" autocomplete="off" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('payment_code') aria-invalid="true" aria-describedby="payment_code-error" @enderror>
            @error('payment_code') <p id="payment_code-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>

        <label for="dni" class="block space-y-1.5 sm:col-span-2">
            <span class="text-sm font-semibold text-slate-800">Identidad (DNI)</span>
            <input id="dni" type="text" inputmode="numeric" wire:model="dni" autocomplete="off" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('dni') aria-invalid="true" aria-describedby="dni-error" @enderror>
            @error('dni') <p id="dni-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <label for="first_name" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Nombre <span aria-hidden="true">*</span></span>
            <input id="first_name" type="text" wire:model="first_name" required autocomplete="given-name" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('first_name') aria-invalid="true" aria-describedby="first_name-error" @enderror>
            @error('first_name') <p id="first_name-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>

        <label for="last_name" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Apellido <span aria-hidden="true">*</span></span>
            <input id="last_name" type="text" wire:model="last_name" required autocomplete="family-name" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('last_name') aria-invalid="true" aria-describedby="last_name-error" @enderror>
            @error('last_name') <p id="last_name-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <label for="sex" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Sexo</span>
            <select id="sex" wire:model="sex" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('sex') aria-invalid="true" aria-describedby="sex-error" @enderror>
                <option value="">Seleccione...</option>
                <option value="M">Masculino</option>
                <option value="F">Femenino</option>
                <option value="O">Otro</option>
            </select>
            @error('sex') <p id="sex-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>

        <label for="birth_date" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Fecha de nacimiento</span>
            <input id="birth_date" type="date" wire:model="birth_date" autocomplete="bday" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('birth_date') aria-invalid="true" aria-describedby="birth_date-error" @enderror>
            @error('birth_date') <p id="birth_date-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <label for="address" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Dirección</span>
            <input id="address" type="text" wire:model="address" autocomplete="street-address" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('address') aria-invalid="true" aria-describedby="address-error" @enderror>
            @error('address') <p id="address-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>

        <label for="phone" class="block space-y-1.5">
            <span class="text-sm font-semibold text-slate-800">Teléfono</span>
            <input id="phone" type="tel" wire:model="phone" autocomplete="tel" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
            @error('phone') <p id="phone-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        </label>
    </div>
</section>
