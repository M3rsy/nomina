<div class="max-w-2xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Cambiar contraseña</h1>

    @if (session('status'))
        <div id="change-password-status" role="status" aria-live="polite" class="mb-4 text-green-600 text-sm">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="space-y-4 bg-white p-6 rounded-lg shadow">
        <div>
            <label for="current-password" class="block text-sm font-medium text-gray-700">Contraseña actual</label>
            <input
                id="current-password"
                type="password"
                wire:model="current_password"
                autocomplete="current-password"
                @error('current_password') aria-describedby="current-password-error" aria-invalid="true" @else aria-invalid="false" @enderror
                class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                required
            >
            @error('current_password')
                <p id="current-password-error" role="alert" class="text-red-600 text-sm">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="new-password" class="block text-sm font-medium text-gray-700">Nueva contraseña</label>
            <input
                id="new-password"
                type="password"
                wire:model="password"
                autocomplete="new-password"
                aria-describedby="new-password-hint @error('password') new-password-error @enderror"
                @error('password') aria-invalid="true" @else aria-invalid="false" @enderror
                class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                required
            >
            <p id="new-password-hint" class="mt-1 text-xs text-gray-500">Use al menos 8 caracteres.</p>
        </div>

        <div>
            <label for="new-password-confirmation" class="block text-sm font-medium text-gray-700">Confirmar nueva contraseña</label>
            <input
                id="new-password-confirmation"
                type="password"
                wire:model="password_confirmation"
                autocomplete="new-password"
                @error('password') aria-describedby="new-password-error" aria-invalid="true" @else aria-invalid="false" @enderror
                class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                required
            >
        </div>

        @error('password')
            <p id="new-password-error" role="alert" class="text-red-600 text-sm">{{ $message }}</p>
        @enderror

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="save"
            class="bg-indigo-600 text-white py-2 px-4 rounded hover:bg-indigo-700 transition disabled:cursor-wait disabled:bg-indigo-400"
        >
            <span wire:loading.remove wire:target="save">Guardar contraseña</span>
            <span wire:loading wire:target="save">Guardando contraseña...</span>
        </button>
        <p role="status" aria-live="polite" class="sr-only">
            <span wire:loading wire:target="save">Guardando contraseña...</span>
        </p>
    </form>
</div>
