<div class="max-w-7xl mx-auto py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Respaldos</h1>
        <button type="button" wire:click="generate" wire:loading.attr="disabled" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 disabled:opacity-50">
            Generar respaldo
        </button>
    </div>

    @if ($message)
        <div class="mb-4 p-4 rounded {{ $messageType === 'success' ? 'bg-green-50 text-green-700' : ($messageType === 'danger' ? 'bg-red-50 text-red-700' : 'bg-yellow-50 text-yellow-700') }}">
            {{ $message }}
        </div>
    @endif

    <div class="bg-blue-50 text-blue-700 p-4 rounded mb-6">
        <p>Los respaldos son globales e incluyen la información de todas las empresas. En MVP no se realizan respaldos por empresa.</p>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-2 text-left">Archivo</th>
                    <th class="px-4 py-2 text-left">Tamaño</th>
                    <th class="px-4 py-2 text-left">Fecha</th>
                    <th class="px-4 py-2 text-left">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($files as $file)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $file['name'] }}</td>
                        <td class="px-4 py-2">{{ $file['size'] }}</td>
                        <td class="px-4 py-2">{{ $file['modified'] }}</td>
                        <td class="px-4 py-2 flex gap-2">
                            <a href="{{ route('respaldos.download', ['path' => $file['path']]) }}" class="text-indigo-600 hover:underline">Descargar</a>
                            @if ($canRestore)
                                <button type="button" wire:click="confirmRestore('{{ $file['path'] }}')" class="text-yellow-600 hover:underline">Restaurar</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-4 text-center text-gray-500">No hay respaldos disponibles.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showRestoreModal)
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-lg p-6 max-w-md w-full">
                <h2 class="text-lg font-semibold mb-4">Restaurar respaldo</h2>
                <p class="text-gray-600 mb-6">La función de restauración no está implementada en MVP. Se registrará este intento.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelRestore" class="px-4 py-2 rounded border border-gray-300 hover:bg-gray-100">Cancelar</button>
                    <button type="button" wire:click="restore" class="px-4 py-2 rounded bg-yellow-600 text-white hover:bg-yellow-700">Entendido</button>
                </div>
            </div>
        </div>
    @endif
</div>
