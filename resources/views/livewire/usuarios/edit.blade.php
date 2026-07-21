<div class="max-w-2xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Editar usuario</h1>

    <form wire:submit="save" class="bg-white p-6 rounded-lg shadow space-y-4">
        <div>
            <label for="name" class="block text-sm font-medium text-gray-700">Nombre</label>
            <input id="name" type="text" wire:model="name" class="mt-1 block w-full rounded border-gray-300 shadow-sm" required>
            @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Correo electrónico</label>
            <input id="email" type="email" wire:model="email" class="mt-1 block w-full rounded border-gray-300 shadow-sm" required>
            @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Nueva contraseña (dejar en blanco para no cambiar)</label>
            <input id="password" type="password" wire:model="password" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
            @error('password') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        @if ($isSuperAdmin)
            <div>
                <label for="role" class="block text-sm font-medium text-gray-700">Rol</label>
                <select id="role" wire:model="role" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
                    <option value="super_admin">Super administrador</option>
                    <option value="company_admin">Administrador de empresa</option>
                </select>
                @error('role') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="company_id" class="block text-sm font-medium text-gray-700">Empresa</label>
                <select id="company_id" wire:model="company_id" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
                    <option value="">Ninguna</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
                @error('company_id') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <label class="flex items-center gap-2"><input type="checkbox" wire:model="is_active" class="rounded border-gray-300"> Cuenta activa</label>
        @endif

        <button type="submit" class="bg-indigo-600 text-white py-2 px-4 rounded hover:bg-indigo-700">Guardar</button>
    </form>
</div>
