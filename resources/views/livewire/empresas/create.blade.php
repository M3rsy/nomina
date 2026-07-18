<div class="max-w-2xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Nueva empresa</h1>

    <form wire:submit="save" class="bg-white p-6 rounded-lg shadow space-y-4">
        <div>
            <label for="name" class="block text-sm font-medium text-gray-700">Nombre</label>
            <input id="name" type="text" wire:model="name" class="mt-1 block w-full rounded border-gray-300 shadow-sm" required>
            @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="legal_id" class="block text-sm font-medium text-gray-700">RTN</label>
            <input id="legal_id" type="text" wire:model="legal_id" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
            @error('legal_id') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div class="flex items-center">
            <input id="is_active" type="checkbox" wire:model="is_active" class="h-4 w-4 text-indigo-600 border-gray-300 rounded">
            <label for="is_active" class="ml-2 block text-sm text-gray-700">Activa</label>
        </div>

        <button type="submit" class="bg-indigo-600 text-white py-2 px-4 rounded hover:bg-indigo-700">Guardar</button>
    </form>
</div>
