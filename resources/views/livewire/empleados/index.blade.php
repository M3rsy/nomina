<div class="min-h-screen bg-slate-50/80">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
        <header class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Gestión de personal</p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Empleados</h1>
                    <p class="mt-2 text-sm text-slate-600">Buscá, filtrá y administrá la nómina de personal por estado.</p>
                </div>

                @can('create', App\Models\Employee::class)
                    <a href="/empleados/crear" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Nuevo empleado</a>
                @endcan
            </div>
        </header>

        <section aria-labelledby="employee-filters-heading" class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                <label for="employee-search" class="min-w-0 flex-1">
                    <span id="employee-filters-heading" class="mb-1.5 block text-sm font-semibold text-slate-800">Buscar empleados</span>
                    <input id="employee-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Código, identidad o nombre" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                </label>

                <label for="employee-filter" class="lg:w-52">
                    <span class="mb-1.5 block text-sm font-semibold text-slate-800">Estado</span>
                    <select id="employee-filter" wire:model.live="filter" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        <option value="active">Activos</option>
                        <option value="inactive">Inactivos</option>
                        <option value="all">Todos</option>
                    </select>
                </label>

                @if ($search !== '' || $filter !== 'active')
                    <button type="button" wire:click="clearFilters" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Limpiar filtros</button>
                @endif
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs">
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-slate-700">Estado: {{ match ($filter) { 'inactive' => 'Inactivos', 'all' => 'Todos', default => 'Activos' } }}</span>
                    @if ($search !== '')
                        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-indigo-700">Búsqueda: <span class="ml-1 font-semibold">{{ $search }}</span></span>
                    @endif
                </div>
                <p role="status" aria-live="polite" class="font-semibold text-slate-600">{{ $employees->total() }} {{ $employees->total() === 1 ? 'resultado' : 'resultados' }}</p>
            </div>
        </section>

        <section aria-labelledby="employees-heading" class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <h2 id="employees-heading" class="sr-only">Resultados de empleados</h2>
            @if ($employees->isEmpty())
                <div class="px-5 py-12 text-center">
                    <div class="mx-auto max-w-md">
                        <h3 class="text-lg font-bold text-slate-900">{{ $search !== '' || $filter !== 'active' ? 'No hay coincidencias' : 'Todavía no hay empleados activos' }}</h3>
                        <p class="mt-2 text-sm text-slate-600">{{ $search !== '' || $filter !== 'active' ? 'Probá con otro término o limpiá los filtros para ampliar los resultados.' : 'Creá el primer empleado para comenzar a administrar la nómina.' }}</p>
                        @if ($search !== '' || $filter !== 'active')
                            <button type="button" wire:click="clearFilters" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Limpiar filtros</button>
                        @else
                            @can('create', App\Models\Employee::class)
                                <a href="/empleados/crear" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Crear empleado</a>
                            @endcan
                        @endif
                    </div>
                </div>
            @else
                <div role="region" aria-labelledby="employees-heading" tabindex="0" class="overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-inset">
                    <table class="w-full text-sm md:min-w-[900px]">
                        <thead class="hidden bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600 md:table-header-group">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-left">Código de empleado</th>
                                <th scope="col" class="px-5 py-3 text-left">Clave</th>
                                <th scope="col" class="px-5 py-3 text-left">Nombre</th>
                                <th scope="col" class="px-5 py-3 text-left">Identidad</th>
                                <th scope="col" class="px-5 py-3 text-left">Cargo</th>
                                <th scope="col" class="px-5 py-3 text-left">Salario esperado</th>
                                @if ($isSuperAdmin)<th scope="col" class="px-5 py-3 text-left">Empresa</th>@endif
                                <th scope="col" class="px-5 py-3 text-left">Estado</th>
                                <th scope="col" class="px-5 py-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 md:divide-slate-100">
                            @foreach ($employees as $employee)
                                <tr data-responsive-employee-row class="block p-4 hover:bg-slate-50/80 md:table-row md:p-0">
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-3.5"><span class="font-semibold text-slate-500 md:hidden">Código</span><span>{{ $employee->external_id }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-3.5"><span class="font-semibold text-slate-500 md:hidden">Clave</span><span>{{ $employee->payment_code ?? '-' }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-3.5"><span class="font-semibold text-slate-500 md:hidden">Nombre</span><span class="font-medium text-slate-900">{{ $employee->full_name }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-3.5"><span class="font-semibold text-slate-500 md:hidden">Identidad</span><span>{{ $employee->dni ?: '-' }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-3.5"><span class="font-semibold text-slate-500 md:hidden">Cargo</span><span>{{ $employee->job_title ?? '-' }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-3.5"><span class="font-semibold text-slate-500 md:hidden">Salario</span><span>{{ $employee->expected_salary !== null ? number_format($employee->expected_salary, 2) : '-' }}</span></td>
                                    @if ($isSuperAdmin)
                                        <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-3.5"><span class="font-semibold text-slate-500 md:hidden">Empresa</span><span>{{ $employee->company?->name ?? '-' }}</span></td>
                                    @endif
                                    <td class="grid grid-cols-[8rem_1fr] items-center gap-3 py-1.5 md:table-cell md:px-5 md:py-3.5">
                                        <span class="font-semibold text-slate-500 md:hidden">Estado</span>
                                        <span class="inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $employee->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">{{ $employee->is_active ? 'Activo' : 'Inactivo' }}</span>
                                    </td>
                                    <td class="mt-2 block border-t border-slate-100 pt-3 md:mt-0 md:table-cell md:border-0 md:px-5 md:py-3.5">
                                        <div data-employee-actions class="flex min-w-max flex-nowrap items-center gap-2">
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
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($employees->hasPages())
            <nav aria-label="Paginación de empleados" class="mt-4">{{ $employees->links() }}</nav>
        @endif
    </div>
</div>
