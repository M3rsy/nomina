<div class="max-w-7xl mx-auto py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Archivos cargados</h1>
        @can('create', App\Models\UploadedFile::class)
            <a href="/archivos/subir" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Subir archivo</a>
        @endcan
    </div>

    <div class="flex flex-col sm:flex-row gap-4 mb-4">
        <input type="text" wire:model.live="search" placeholder="Buscar por nombre..." class="w-full max-w-md rounded border-gray-300 shadow-sm">

        <select wire:model.live="status" class="rounded border-gray-300 shadow-sm">
            <option value="">Todos los estados</option>
            <option value="valid">Válido</option>
            <option value="valid_with_warnings">Válido con advertencias</option>
            <option value="invalid">Inválido</option>
            <option value="pending">Pendiente</option>
        </select>
    </div>

    <table class="w-full bg-white rounded-lg shadow">
        <thead class="bg-gray-100">
            <tr>
                <th class="px-4 py-2 text-left">Nombre original</th>
                <th class="px-4 py-2 text-left">Período</th>
                <th class="px-4 py-2 text-left">Estado</th>
                <th class="px-4 py-2 text-left">Registros</th>
                <th class="px-4 py-2 text-left">Fecha</th>
                <th class="px-4 py-2 text-left">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($files as $file)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $file->original_name }}</td>
                    <td class="px-4 py-2">{{ $file->payPeriod?->name ?? '-' }}</td>
                    <td class="px-4 py-2">{{ $file->status }}</td>
                    <td class="px-4 py-2">{{ $file->raw_marks_count }}</td>
                    <td class="px-4 py-2">{{ $file->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-2">
                        <a href="/archivos/{{ $file->id }}" class="text-indigo-600 hover:underline">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-4 text-center text-gray-500">No se encontraron archivos.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">
        {{ $files->links() }}
    </div>
</div>
