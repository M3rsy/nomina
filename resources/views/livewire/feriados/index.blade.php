<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f8f1ff_38%,_#ffffff_80%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Calendario laboral
                    </p>
                    <h1 class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Feriados</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">Gestioná días no laborables con altas, ediciones y estado activo por empresa.</p>
                </div>

                @can('holidays.manage')
                    <button
                        wire:click="openCreateModal"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Crear feriado
                    </button>
                @endcan
            </div>
        </header>

        @if (! $hasCompany)
            <div class="rounded-3xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                Seleccione una empresa para gestionar sus feriados.
            </div>
        @else
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Búsqueda y filtros</h2>
                        <p class="mt-1 text-xs text-slate-600">Buscá por nombre o descripción para editar y activar feriados rápido.</p>
                    </div>
                    @if ($search !== '')
                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            Limpiar filtros
                        </button>
                    @endif
                </div>

                <label class="block" for="holidays-search">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Buscar</span>
                    <input
                        id="holidays-search"
                        type="text"
                        wire:model.live="search"
                        placeholder="Buscar por nombre o descripción..."
                        class="h-11 w-full max-w-sm rounded-xl border border-slate-300 bg-white px-3 text-sm"
                    />
                </label>

                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    @if ($search !== '')
                        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-indigo-700">
                            Búsqueda: <span class="ml-1 font-semibold">{{ $search }}</span>
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-slate-600">
                            Sin filtros activos
                        </span>
                    @endif
                </div>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-slate-50/90 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-4 py-3">Fecha</th>
                                <th class="px-4 py-3">Nombre</th>
                                <th class="px-4 py-3">Descripción</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($holidays as $holiday)
                                <tr class="border-t border-slate-200">
                                    <td class="px-4 py-3.5 text-sm text-slate-900">{{ $holiday->date->format('Y-m-d') }}</td>
                                    <td class="px-4 py-3.5 text-sm font-semibold text-slate-900">{{ $holiday->name }}</td>
                                    <td class="px-4 py-3.5 text-sm text-slate-600">{{ $holiday->description ?? '-' }}</td>
                                    <td class="px-4 py-3.5 text-sm">
                                        @can('holidays.manage')
                                            <button
                                                wire:click="toggle({{ $holiday->id }})"
                                                class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $holiday->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-600' }}"
                                            >
                                                {{ $holiday->is_active ? 'Activo' : 'Inactivo' }}
                                            </button>
                                        @else
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $holiday->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-600' }}">
                                                {{ $holiday->is_active ? 'Activo' : 'Inactivo' }}
                                            </span>
                                        @endcan
                                    </td>
                                    <td class="px-4 py-3.5">
                                        @can('holidays.manage')
                                            <div class="flex flex-wrap gap-2">
                                                <button
                                                    wire:click="edit({{ $holiday->id }})"
                                                    class="inline-flex min-h-9 items-center rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100"
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    wire:click="confirmDelete({{ $holiday->id }})"
                                                    class="inline-flex min-h-9 items-center rounded-lg border border-rose-100 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
                                                >
                                                    Eliminar
                                                </button>
                                            </div>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">Sin feriados registrados.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="pt-1">{{ $holidays->links() }}</div>
        @endif
    </div>

    @if ($showCreateModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
                <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Editar feriado' : 'Crear feriado' }}</h2>

                <div class="mt-4 space-y-4">
                    <div>
                        <label for="holiday-date" class="block text-sm font-medium text-slate-700">Fecha</label>
                        <input
                            id="holiday-date"
                            type="date"
                            wire:model="formDate"
                            class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                        />
                        @error('formDate') <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="holiday-name" class="block text-sm font-medium text-slate-700">Nombre</label>
                        <input
                            id="holiday-name"
                            type="text"
                            wire:model="formName"
                            class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                        />
                        @error('formName') <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="holiday-description" class="block text-sm font-medium text-slate-700">Descripción</label>
                        <textarea
                            id="holiday-description"
                            wire:model="formDescription"
                            rows="2"
                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm"
                        ></textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input id="holiday-active" type="checkbox" wire:model="formIsActive" class="h-4 w-4 rounded border-slate-300 text-indigo-600" />
                        Activo
                    </label>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button
                        wire:click="closeCreateModal"
                        type="button"
                        class="inline-flex min-h-10 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >Cancelar</button>
                    <button
                        wire:click="save"
                        type="button"
                        class="inline-flex min-h-10 items-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700"
                    >Guardar</button>
                </div>
            </div>
        </div>
    @endif

    @if ($confirmingDelete)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-sm rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
                <h2 class="text-lg font-semibold text-slate-900">Eliminar feriado</h2>
                <p class="mt-2 text-sm text-slate-600">¿Confirmá que querés eliminar este feriado?</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        wire:click="$set('confirmingDelete', false)"
                        type="button"
                        class="inline-flex min-h-10 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >Cancelar</button>
                    <button
                        wire:click="delete"
                        type="button"
                        class="inline-flex min-h-10 items-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700"
                    >Eliminar</button>
                </div>
            </div>
        </div>
    @endif
</div>
