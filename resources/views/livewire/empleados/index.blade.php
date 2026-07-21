<div class="min-h-screen bg-slate-50/80">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <header class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Gestión de personal</p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Empleados</h1>
                    <p class="mt-2 text-sm text-slate-600">Buscá, filtrá y administrá la nómina de personal por estado.</p>
                </div>

                @can('create', App\Models\Employee::class)
                    <a href="/empleados/crear" class="inline-flex min-h-11 shrink-0 items-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Nuevo empleado</a>
                @endcan
            </div>
        </header>

        <section aria-labelledby="employee-filters-heading" class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 id="employee-filters-heading" class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Búsqueda y filtros</h2>

                <div class="text-xs text-slate-600" role="status" aria-live="polite">
                    @if ($search !== '')
                        <span class="inline-flex rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-indigo-700">Filtro activo</span>
                    @endif
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label for="employee-search" class="sm:col-span-3">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Buscar</span>
                    <input id="employee-search" type="text" wire:model.live="search" placeholder="Buscar por código, identidad o nombre..." class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30" aria-label="Buscar empleados">
                </label>

                <label for="employee-filter">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Estado</span>
                    <select id="employee-filter" wire:model.live="filter" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        <option value="active">Activos</option>
                        <option value="all">Todos</option>
                    </select>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-slate-700">Filtro: {{ $filter === 'all' ? 'Todos' : 'Activos' }}</span>
                @if ($search === '')
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-slate-700">Sin búsqueda</span>
                @else
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-indigo-700">Búsqueda: <span class="ml-1 font-semibold">{{ $search }}</span></span>
                @endif
            </div>
        </section>

        <section aria-labelledby="employees-heading" class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div role="region" aria-labelledby="employees-heading" tabindex="0" class="overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                <table class="min-w-[840px] w-full text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-left">Código</th>
                            <th scope="col" class="px-5 py-3 text-left">Nombre</th>
                            <th scope="col" class="px-5 py-3 text-left">Identidad</th>
                            <th scope="col" class="px-5 py-3 text-left">Cargo</th>
                            <th scope="col" class="px-5 py-3 text-left">Salario esperado</th>
                            <th scope="col" class="px-5 py-3 text-left">Estado</th>
                            <th scope="col" class="px-5 py-3 text-left">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($employees as $employee)
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-5 py-3.5">{{ $employee->external_id }}</td>
                                <td class="px-5 py-3.5 font-medium text-slate-900">{{ $employee->full_name }}</td>
                                <td class="px-5 py-3.5">{{ $employee->dni }}</td>
                                <td class="px-5 py-3.5">{{ $employee->job_title ?? '-' }}</td>
                                <td class="px-5 py-3.5">{{ $employee->expected_salary !== null ? number_format($employee->expected_salary, 2) : '-' }}</td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $employee->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                                        {{ $employee->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @can('update', $employee)
                                            <a href="/empleados/{{ $employee->id }}/editar" class="inline-flex min-h-9 items-center rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1.5 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Editar</a>
                                        @endcan

                                        @can('activate', $employee)
                                            <livewire:empleados.toggle-activate :employee="$employee" :key="'toggle-'.$employee->id" />
                                        @endcan

                                        @can('delete', $employee)
                                            <livewire:empleados.delete :employee="$employee" :key="'delete-'.$employee->id" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-500">No se encontraron empleados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-4">
            {{ $employees->links() }}
        </div>
    </div>
</div>
