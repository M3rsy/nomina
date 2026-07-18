<div class="max-w-7xl mx-auto py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Empleados</h1>
        @can('create', App\Models\Employee::class)
            <a href="/empleados/crear" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Nuevo empleado</a>
        @endcan
    </div>

    <div class="flex flex-col sm:flex-row gap-4 mb-4">
        <input type="text" wire:model.live="search" placeholder="Buscar por código, identidad o nombre..." class="w-full max-w-md rounded border-gray-300 shadow-sm">

        <select wire:model.live="filter" class="rounded border-gray-300 shadow-sm">
            <option value="active">Activos</option>
            <option value="all">Todos</option>
        </select>
    </div>

    <table class="w-full bg-white rounded-lg shadow">
        <thead class="bg-gray-100">
            <tr>
                <th class="px-4 py-2 text-left">Código</th>
                <th class="px-4 py-2 text-left">Nombre</th>
                <th class="px-4 py-2 text-left">Identidad</th>
                <th class="px-4 py-2 text-left">Cargo</th>
                <th class="px-4 py-2 text-left">Salario esperado</th>
                <th class="px-4 py-2 text-left">Estado</th>
                <th class="px-4 py-2 text-left">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($employees as $employee)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $employee->external_id }}</td>
                    <td class="px-4 py-2">{{ $employee->full_name }}</td>
                    <td class="px-4 py-2">{{ $employee->dni }}</td>
                    <td class="px-4 py-2">{{ $employee->job_title ?? '-' }}</td>
                    <td class="px-4 py-2">{{ $employee->expected_salary !== null ? number_format($employee->expected_salary, 2) : '-' }}</td>
                    <td class="px-4 py-2">{{ $employee->is_active ? 'Activo' : 'Inactivo' }}</td>
                    <td class="px-4 py-2 space-x-2">
                        @can('update', $employee)
                            <a href="/empleados/{{ $employee->id }}/editar" class="text-indigo-600 hover:underline">Editar</a>
                        @endcan

                        @can('activate', $employee)
                            <livewire:empleados.toggle-activate :employee="$employee" :key="'toggle-'.$employee->id" />
                        @endcan

                        @can('delete', $employee)
                            <livewire:empleados.delete :employee="$employee" :key="'delete-'.$employee->id" />
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-4 text-center text-gray-500">No se encontraron empleados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">
        {{ $employees->links() }}
    </div>
</div>
