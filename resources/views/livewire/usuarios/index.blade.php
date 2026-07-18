<div class="max-w-6xl mx-auto py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Usuarios</h1>
        @can('create', App\Models\User::class)
            <a href="/usuarios/crear" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Nuevo usuario</a>
        @endcan
    </div>

    <input type="text" wire:model.live="search" placeholder="Buscar..." class="mb-4 w-full max-w-md rounded border-gray-300 shadow-sm">

    <table class="w-full bg-white rounded-lg shadow">
        <thead class="bg-gray-100">
            <tr>
                <th class="px-4 py-2 text-left">Nombre</th>
                <th class="px-4 py-2 text-left">Correo</th>
                <th class="px-4 py-2 text-left">Empresa</th>
                <th class="px-4 py-2 text-left">Rol</th>
                <th class="px-4 py-2 text-left">Estado</th>
                <th class="px-4 py-2 text-left">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $user)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $user->name }}</td>
                    <td class="px-4 py-2">{{ $user->email }}</td>
                    <td class="px-4 py-2">{{ $user->company?->name ?? '-' }}</td>
                    <td class="px-4 py-2">{{ $user->getRoleNames()->first() }}</td>
                    <td class="px-4 py-2">{{ $user->is_active ? 'Activo' : 'Inactivo' }}</td>
                    <td class="px-4 py-2">
                        @can('update', $user)
                            <a href="/usuarios/{{ $user->id }}/editar" class="text-indigo-600 hover:underline">Editar</a>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
