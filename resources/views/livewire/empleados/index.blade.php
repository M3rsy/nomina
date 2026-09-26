<div class="min-h-screen bg-surface-muted">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section data-employee-section="hero" class="relative overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="employees-page-heading">
            <div class="h-1.5 bg-gradient-to-r from-dashboard-accent via-brand to-dashboard-info" aria-hidden="true"></div>
            <div class="flex flex-col gap-6 p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-dashboard-accent-subtle px-3 py-1 text-xs font-bold uppercase tracking-wider text-dashboard-accent-strong">Gestión de personal</span>
                        <span class="inline-flex items-center rounded-full bg-brand-subtle px-3 py-1 text-xs font-semibold text-brand-strong">{{ $employees->total() }} {{ $employees->total() === 1 ? 'empleado' : 'empleados' }}</span>
                    </div>
                    <h1 id="employees-page-heading" class="mt-4 text-3xl font-black tracking-tight text-text sm:text-4xl">Directorio oficial</h1>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-text-muted">Consultá y administrá la información vigente del personal desde un único lugar.</p>
                </div>

                @can('create', App\Models\Employee::class)
                    <x-ui.button href="/empleados/crear">Nuevo empleado</x-ui.button>
                @endcan
            </div>
        </section>

        <section data-employee-section="metrics" aria-labelledby="employee-summary-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-brand">Resumen del directorio</p>
                    <h2 id="employee-summary-heading" class="mt-1 text-xl font-bold text-text">Vista actual</h2>
                </div>
                <span class="rounded-full border border-border bg-surface px-3 py-1 text-xs font-semibold text-text-muted">Datos según los filtros aplicados</span>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                    <p class="text-sm font-semibold text-text-muted">Resultados totales</p>
                    <p class="mt-2 text-3xl font-black text-text">{{ $employees->total() }}</p>
                    <p class="mt-2 text-xs text-text-muted">Coincidencias en todas las páginas.</p>
                </article>
                <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                    <p class="text-sm font-semibold text-text-muted">Estado consultado</p>
                    <p class="mt-2 text-xl font-black text-text">{{ match ($filter) { 'inactive' => 'Inactivos', 'all' => 'Todos', default => 'Activos' } }}</p>
                    <p class="mt-2 text-xs text-text-muted">Segmento actual del directorio.</p>
                </article>
                <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                    <p class="text-sm font-semibold text-text-muted">En esta página</p>
                    <p class="mt-2 text-3xl font-black text-text">{{ $employees->count() }}</p>
                    <p class="mt-2 text-xs text-text-muted">{{ $employees->count() }} {{ $employees->count() === 1 ? 'visible' : 'visibles' }} ahora.</p>
                </article>
                <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                    <p class="text-sm font-semibold text-text-muted">Búsqueda</p>
                    <p class="mt-2 text-xl font-black text-text">{{ $search !== '' ? 'Búsqueda activa' : 'Búsqueda inactiva' }}</p>
                    <p class="mt-2 truncate text-xs text-text-muted">{{ $search !== '' ? 'Término: '.$search : 'Sin término aplicado.' }}</p>
                </article>
            </div>
        </section>

        <section data-employee-section="filters" aria-labelledby="employee-filters-heading" class="rounded-3xl border border-border bg-surface p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                <label for="employee-search" class="min-w-0 flex-1">
                    <span id="employee-filters-heading" class="mb-1.5 block text-sm font-semibold text-text">Buscar empleados</span>
                    <input id="employee-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Código, identidad o nombre" class="h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30">
                </label>

                <label for="employee-filter" class="lg:w-52">
                    <span class="mb-1.5 block text-sm font-semibold text-text">Estado</span>
                    <select id="employee-filter" wire:model.live="filter" class="h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30">
                        <option value="active">Activos</option>
                        <option value="inactive">Inactivos</option>
                        <option value="all">Todos</option>
                    </select>
                </label>

                @if ($search !== '' || $filter !== 'active')
                    <x-ui.button wire:click="clearFilters" variant="secondary">Limpiar filtros</x-ui.button>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4 text-xs">
                <div class="flex flex-wrap gap-2" aria-label="Filtros aplicados">
                    <x-ui.badge>Estado: {{ match ($filter) { 'inactive' => 'Inactivos', 'all' => 'Todos', default => 'Activos' } }}</x-ui.badge>
                    @if ($search !== '')
                        <x-ui.badge variant="brand">Búsqueda: {{ $search }}</x-ui.badge>
                    @endif
                </div>
                <p role="status" aria-live="polite" class="font-semibold text-text-muted">{{ $employees->total() }} {{ $employees->total() === 1 ? 'resultado' : 'resultados' }}</p>
            </div>
        </section>

        <section data-employee-section="directory" aria-labelledby="employees-heading" class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm">
            <div class="flex flex-col gap-2 border-b border-border px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-7">
                <div class="min-w-0 sm:min-w-72">
                    <p class="text-xs font-bold uppercase tracking-wider text-dashboard-accent-strong">Personal registrado</p>
                    <h2 id="employees-heading" class="mt-1 text-xl font-bold text-text">Empleados</h2>
                </div>
                <p class="text-sm text-text-muted">{{ $employees->count() }} de {{ $employees->total() }} resultados visibles</p>
            </div>

            @if ($employees->isEmpty())
                <div class="p-5 sm:p-7">
                    <x-ui.empty-state :title="$search !== '' || $filter !== 'active' ? 'No hay coincidencias' : 'Todavía no hay empleados activos'">
                        {{ $search !== '' || $filter !== 'active' ? 'Probá con otro término o limpiá los filtros para ampliar los resultados.' : 'Creá el primer empleado para comenzar a administrar la nómina.' }}
                        <x-slot:actions>
                            @if ($search !== '' || $filter !== 'active')
                                <x-ui.button wire:click="clearFilters" variant="secondary">Limpiar filtros</x-ui.button>
                            @else
                                @can('create', App\Models\Employee::class)
                                    <x-ui.button href="/empleados/crear">Crear empleado</x-ui.button>
                                @endcan
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                </div>
            @else
                <div role="region" aria-labelledby="employees-heading" tabindex="0" class="overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-inset">
                    <table class="w-full text-sm text-text md:min-w-[980px]">
                        <thead class="hidden bg-surface-muted text-xs font-semibold uppercase tracking-wide text-text-muted md:table-header-group">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-left">Empleado</th>
                                <th scope="col" class="px-5 py-3 text-left">Código de empleado</th>
                                <th scope="col" class="px-5 py-3 text-left">Clave</th>
                                <th scope="col" class="px-5 py-3 text-left">Identidad</th>
                                <th scope="col" class="px-5 py-3 text-left">Cargo</th>
                                <th scope="col" class="px-5 py-3 text-left">Salario esperado</th>
                                @if ($isSuperAdmin)<th scope="col" class="px-5 py-3 text-left">Empresa</th>@endif
                                <th scope="col" class="px-5 py-3 text-left">Estado</th>
                                <th scope="col" class="px-5 py-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($employees as $employee)
                                @php
                                    $initials = mb_strtoupper(mb_substr($employee->first_name, 0, 1).mb_substr($employee->last_name, 0, 1));
                                @endphp
                                <tr data-responsive-employee-row class="block p-4 transition hover:bg-surface-muted/70 md:table-row md:p-0">
                                    <td class="grid grid-cols-[8rem_1fr] items-center gap-3 py-1.5 md:table-cell md:px-5 md:py-4">
                                        <span class="font-semibold text-text-muted md:hidden">Empleado</span>
                                        <span class="flex min-w-0 items-center gap-3">
                                            <span data-employee-avatar class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-subtle text-xs font-black text-brand-strong" aria-hidden="true">{{ $initials }}</span>
                                            <span class="min-w-0 font-semibold text-text">{{ $employee->full_name }}</span>
                                        </span>
                                    </td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-4"><span class="font-semibold text-text-muted md:hidden">Código</span><span>{{ $employee->external_id }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-4"><span class="font-semibold text-text-muted md:hidden">Clave</span><span>{{ $employee->payment_code ?? '-' }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-4"><span class="font-semibold text-text-muted md:hidden">Identidad</span><span>{{ $employee->dni ?: '-' }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-4"><span class="font-semibold text-text-muted md:hidden">Cargo</span><span>{{ $employee->job_title ?? '-' }}</span></td>
                                    <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-4"><span class="font-semibold text-text-muted md:hidden">Salario</span><span class="font-semibold">{{ $employee->expected_salary !== null ? number_format($employee->expected_salary, 2) : '-' }}</span></td>
                                    @if ($isSuperAdmin)
                                        <td class="grid grid-cols-[8rem_1fr] gap-3 py-1.5 md:table-cell md:px-5 md:py-4"><span class="font-semibold text-text-muted md:hidden">Empresa</span><span>{{ $employee->company?->name ?? '-' }}</span></td>
                                    @endif
                                    <td class="grid grid-cols-[8rem_1fr] items-center gap-3 py-1.5 md:table-cell md:px-5 md:py-4">
                                        <span class="font-semibold text-text-muted md:hidden">Estado</span>
                                        <x-ui.badge :variant="$employee->is_active ? 'success' : 'neutral'">{{ $employee->is_active ? 'Activo' : 'Inactivo' }}</x-ui.badge>
                                    </td>
                                    <td class="mt-2 block border-t border-border pt-3 md:mt-0 md:table-cell md:border-0 md:px-5 md:py-4">
                                        <div data-employee-actions class="flex min-w-max flex-nowrap items-center gap-2">
                                            @can('update', $employee)
                                                <a href="/empleados/{{ $employee->id }}/editar" class="inline-flex min-h-9 items-center rounded-lg border border-brand/20 bg-brand-subtle px-3 py-1.5 text-sm font-semibold text-brand-strong transition hover:bg-brand/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">Editar</a>
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

            <footer class="flex flex-col gap-3 border-t border-border bg-surface-muted px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                <p class="text-sm text-text-muted">Mostrando {{ $employees->count() }} de {{ $employees->total() }} resultados.</p>
                @if ($employees->hasPages())
                    <nav aria-label="Paginación de empleados">{{ $employees->links() }}</nav>
                @endif
            </footer>
        </section>
    </div>
</div>
