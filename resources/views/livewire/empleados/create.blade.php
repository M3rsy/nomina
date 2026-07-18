<div class="max-w-2xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Nuevo empleado</h1>

    <form wire:submit="save" class="bg-white p-6 rounded-lg shadow space-y-4">
        @if ($isSuperAdmin)
            <div>
                <label for="company_id" class="block text-sm font-medium text-gray-700">Empresa</label>
                <select id="company_id" wire:model="company_id" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
                    <option value="">Seleccione...</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
                @error('company_id') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        @endif

        <div>
            <label for="external_id" class="block text-sm font-medium text-gray-700">Código de empleado</label>
            <input id="external_id" type="text" wire:model="external_id" class="mt-1 block w-full rounded border-gray-300 shadow-sm" required>
            @error('external_id') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="first_name" class="block text-sm font-medium text-gray-700">Nombre</label>
                <input id="first_name" type="text" wire:model="first_name" class="mt-1 block w-full rounded border-gray-300 shadow-sm" required>
                @error('first_name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="last_name" class="block text-sm font-medium text-gray-700">Apellido</label>
                <input id="last_name" type="text" wire:model="last_name" class="mt-1 block w-full rounded border-gray-300 shadow-sm" required>
                @error('last_name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </div>

        <div>
            <label for="dni" class="block text-sm font-medium text-gray-700">Identidad (DNI)</label>
            <input id="dni" type="text" wire:model="dni" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
            @error('dni') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="sex" class="block text-sm font-medium text-gray-700">Sexo</label>
                <select id="sex" wire:model="sex" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
                    <option value="">Seleccione...</option>
                    <option value="M">Masculino</option>
                    <option value="F">Femenino</option>
                    <option value="O">Otro</option>
                </select>
                @error('sex') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="birth_date" class="block text-sm font-medium text-gray-700">Fecha de nacimiento</label>
                <input id="birth_date" type="date" wire:model="birth_date" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
                @error('birth_date') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </div>

        <div>
            <label for="address" class="block text-sm font-medium text-gray-700">Dirección</label>
            <input id="address" type="text" wire:model="address" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
            @error('address') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium text-gray-700">Teléfono</label>
            <input id="phone" type="text" wire:model="phone" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
            @error('phone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="job_title" class="block text-sm font-medium text-gray-700">Cargo</label>
            <input id="job_title" type="text" wire:model="job_title" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
            @error('job_title') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="expected_salary" class="block text-sm font-medium text-gray-700">Salario esperado</label>
            <input id="expected_salary" type="number" step="0.01" wire:model="expected_salary" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
            @error('expected_salary') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="hired_at" class="block text-sm font-medium text-gray-700">Fecha de contratación</label>
            <input id="hired_at" type="date" wire:model="hired_at" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
            @error('hired_at') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-gray-700">Notas</label>
            <textarea id="notes" wire:model="notes" rows="3" class="mt-1 block w-full rounded border-gray-300 shadow-sm"></textarea>
            @error('notes') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <button type="submit" class="bg-indigo-600 text-white py-2 px-4 rounded hover:bg-indigo-700">Guardar</button>
    </form>
</div>
