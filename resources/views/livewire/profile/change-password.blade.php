<div class="max-w-2xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Cambiar contraseña</h1>

    @if (session('status'))
        <div class="mb-4 text-green-600 text-sm">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="space-y-4 bg-white p-6 rounded-lg shadow">
        <div>
            <label for="current_password" class="block text-sm font-medium text-gray-700">Contraseña actual</label>
            <input id="current_password" type="password" wire:model="current_password" class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            @error('current_password') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Nueva contraseña</label>
            <input id="password" type="password" wire:model="password" class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            @error('password') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirmar nueva contraseña</label>
            <input id="password_confirmation" type="password" wire:model="password_confirmation" class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
        </div>

        <button type="submit" class="bg-indigo-600 text-white py-2 px-4 rounded hover:bg-indigo-700 transition">
            Guardar contraseña
        </button>
    </form>
</div>
