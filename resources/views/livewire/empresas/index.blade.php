<div class="max-w-6xl mx-auto py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 id="companies-heading" class="text-2xl font-bold">Empresas</h1>
        @can('create', App\Models\Company::class)
            <a href="/empresas/crear" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Nueva empresa</a>
        @endcan
    </div>

    <input type="text" wire:model.live="search" placeholder="Buscar..." class="mb-4 w-full max-w-md rounded border-gray-300 shadow-sm">

    <div role="region" aria-labelledby="companies-heading" tabindex="0" class="overflow-x-auto rounded-lg shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
        <table class="w-full bg-white">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-2 text-left">Nombre</th>
                    <th class="px-4 py-2 text-left">Slug</th>
                    <th class="px-4 py-2 text-left">RTN</th>
                    <th class="px-4 py-2 text-left">Estado</th>
                    <th class="px-4 py-2 text-left">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($companies as $company)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $company->name }}</td>
                        <td class="px-4 py-2">{{ $company->slug }}</td>
                        <td class="px-4 py-2">{{ $company->legal_id }}</td>
                        <td class="px-4 py-2">{{ $company->is_active ? 'Activa' : 'Inactiva' }}</td>
                        <td class="px-4 py-2 space-x-2">
                            @can('update', $company)
                                <a href="/empresas/{{ $company->id }}/editar" class="text-indigo-600 hover:underline">Editar</a>
                            @endcan
                            @can('activate', $company)
                                <button wire:click="toggle({{ $company->id }})" class="text-gray-600 hover:underline">{{ $company->is_active ? 'Desactivar' : 'Activar' }}</button>
                            @endcan
                            @can('delete', $company)
                                <button wire:click="delete({{ $company->id }})" class="text-red-600 hover:underline" onclick="return confirm('¿Eliminar empresa?')">Eliminar</button>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $companies->links() }}
    </div>
</div>
