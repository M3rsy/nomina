<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Feriados</h1>
            @can('holidays.manage')
                <button
                    wire:click="openCreateModal"
                    class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-700"
                >
                    Crear feriado
                </button>
            @endcan
        </div>

        @if (! $hasCompany)
            <div class="rounded-md bg-yellow-50 p-4 text-sm text-yellow-700">
                Seleccione una empresa para gestionar sus feriados.
            </div>
        @else
            <div class="mb-4">
                <input
                    type="text"
                    wire:model.live="search"
                    placeholder="Buscar..."
                    class="w-full max-w-sm rounded-md border-gray-300"
                />
            </div>

            <div class="bg-white shadow rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Fecha</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nombre</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Descripción</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Activo</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($holidays as $holiday)
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900">{{ $holiday->date->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900">{{ $holiday->name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $holiday->description ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @can('holidays.manage')
                                        <button
                                            wire:click="toggle({{ $holiday->id }})"
                                            class="text-xs font-semibold {{ $holiday->is_active ? 'text-green-600' : 'text-gray-400' }}"
                                        >
                                            {{ $holiday->is_active ? 'Activo' : 'Inactivo' }}
                                        </button>
                                    @else
                                        <span class="text-xs {{ $holiday->is_active ? 'text-green-600' : 'text-gray-400' }}">
                                            {{ $holiday->is_active ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    @endcan
                                </td>
                                <td class="px-4 py-3 text-sm space-x-2">
                                    @can('holidays.manage')
                                        <button
                                            wire:click="edit({{ $holiday->id }})"
                                            class="text-indigo-600 hover:text-indigo-800"
                                        >Editar</button>
                                        <button
                                            wire:click="confirmDelete({{ $holiday->id }})"
                                            class="text-red-600 hover:text-red-800"
                                        >Eliminar</button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">Sin feriados registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $holidays->links() }}
        @endif
    </div>

    @if ($showCreateModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-gray-500/50">
            <div class="w-full max-w-md rounded-md bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar feriado' : 'Crear feriado' }}</h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Fecha</label>
                        <input
                            type="date"
                            wire:model="formDate"
                            class="mt-1 w-full rounded-md border-gray-300"
                        />
                        @error('formDate') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nombre</label>
                        <input
                            type="text"
                            wire:model="formName"
                            class="mt-1 w-full rounded-md border-gray-300"
                        />
                        @error('formName') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Descripción</label>
                        <textarea
                            wire:model="formDescription"
                            rows="2"
                            class="mt-1 w-full rounded-md border-gray-300"
                        ></textarea>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <input type="checkbox" wire:model="formIsActive" class="h-4 w-4 rounded border-gray-300 text-indigo-600" />
                            Activo
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button
                        wire:click="closeCreateModal"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >Cancelar</button>
                    <button
                        wire:click="save"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-700"
                    >Guardar</button>
                </div>
            </div>
        </div>
    @endif

    @if ($confirmingDelete)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-gray-500/50">
            <div class="w-full max-w-sm rounded-md bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold mb-2">Eliminar feriado</h2>
                <p class="text-sm text-gray-600 mb-4">¿Confirmá que querés eliminar este feriado?</p>
                <div class="flex justify-end gap-2">
                    <button
                        wire:click="$set('confirmingDelete', false)"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >Cancelar</button>
                    <button
                        wire:click="delete"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-red-700"
                    >Eliminar</button>
                </div>
            </div>
        </div>
    @endif
</div>