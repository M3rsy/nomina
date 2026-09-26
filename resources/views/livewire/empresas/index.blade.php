@php
    $visibleCompanies = $companies->getCollection();
    $visibleCount = $visibleCompanies->count();
    $visibleActiveCount = $visibleCompanies->where('is_active', true)->count();
    $visibleInactiveCount = $visibleCompanies->where('is_active', false)->count();
    $activeCompany = current_company();
@endphp

<div class="min-h-screen bg-surface-muted" data-companies-index="workspace">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <nav aria-label="Miga de pan" class="flex flex-wrap items-center gap-2 text-sm font-semibold text-text-muted">
            <span class="rounded-full bg-surface px-3 py-1 text-xs font-bold uppercase tracking-wider text-brand">Gestión Empresarial</span>
            <span aria-hidden="true">/</span>
            <span>Directorio Corporativo Multi-Entidad</span>
            <span class="rounded-full bg-brand/10 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-brand">Multi-Tenant</span>
        </nav>

        <x-ui.page-header
            title="Empresas"
            description="Administrá las entidades corporativas, su identificación fiscal y su estado operativo sin mezclar información entre tenants."
        >
            @can('create', App\Models\Company::class)
                <x-slot:actions>
                    <x-ui.button :href="route('empresas.create')">
                        Nueva empresa
                    </x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.page-header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de empresas">
            <x-ui.card class="relative overflow-hidden">
                <div class="absolute inset-x-0 top-0 h-1 bg-brand" aria-hidden="true"></div>
                <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Empresas registradas</p>
                <p class="mt-3 text-3xl font-black tracking-tight text-text">{{ number_format($companies->total()) }}</p>
                <p class="mt-2 text-sm text-text-muted">Coinciden con la búsqueda actual.</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Empresas visibles</p>
                <p class="mt-3 text-3xl font-black tracking-tight text-text">{{ $visibleCount }}</p>
                <p class="mt-2 text-sm text-text-muted">{{ $visibleActiveCount }} activas · {{ $visibleInactiveCount }} inactivas en esta página.</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Empresa activa</p>
                @if ($activeCompany)
                    <p class="mt-3 truncate text-2xl font-black tracking-tight text-text">{{ $activeCompany->name }}</p>
                    <p class="mt-2 truncate text-sm font-semibold text-text-muted">{{ $activeCompany->legal_id ?? 'Sin RTN registrado' }}</p>
                @else
                    <p class="mt-3 text-2xl font-black tracking-tight text-text-muted">Sin selección</p>
                    <p class="mt-2 text-sm text-text-muted">Seleccioná una empresa para operar módulos tenant-scoped.</p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Aislamiento tenant</p>
                <p class="mt-3 text-2xl font-black tracking-tight text-success-strong">Segregado</p>
                <p class="mt-2 text-sm text-text-muted">Cada empresa mantiene colaboradores, nómina y configuraciones separadas.</p>
            </x-ui.card>
        </section>

        <x-ui.card aria-labelledby="companies-filters-heading">
            <x-slot:header>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-brand">Búsqueda y filtros</p>
                        <h2 id="companies-filters-heading" class="mt-1 text-xl font-bold text-text">Directorio corporativo</h2>
                        <p class="mt-1 text-sm text-text-muted">Buscá por nombre comercial, slug corporativo o RTN.</p>
                    </div>
                    @if ($search !== '')
                        <x-ui.button type="button" variant="secondary" wire:click="$set('search', '')">
                            Limpiar filtros
                        </x-ui.button>
                    @endif
                </div>
            </x-slot:header>

            <x-ui.input
                id="companies-search"
                label="Buscar empresas"
                wire:model.live="search"
                placeholder="Nombre, slug o RTN..."
            />

            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                @if ($search !== '')
                    <span class="inline-flex items-center rounded-full border border-brand/20 bg-brand/10 px-3 py-1 text-brand">
                        Búsqueda: <span class="ml-1 font-semibold">{{ $search }}</span>
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full border border-border bg-surface-muted px-3 py-1 text-text-muted">
                        Sin filtros activos
                    </span>
                @endif
                @if ($activeCompany)
                    <span class="inline-flex items-center rounded-full border border-success/20 bg-success/10 px-3 py-1 text-success-strong">
                        Empresa activa: <span class="ml-1 font-semibold">{{ $activeCompany->name }}</span>
                    </span>
                @endif
            </div>
        </x-ui.card>

        <section class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="companies-heading">
            <div class="border-b border-border bg-surface px-5 py-5 sm:px-7">
                <p class="text-xs font-bold uppercase tracking-wider text-brand">Empresas registradas</p>
                <h2 id="companies-heading" class="mt-1 text-xl font-bold text-text">Empresas</h2>
                <p class="mt-1 text-sm text-text-muted">Acciones disponibles según permisos: editar, activar/desactivar o eliminar.</p>
            </div>

            <div
                role="region"
                aria-labelledby="companies-heading"
                tabindex="0"
                class="overflow-x-auto"
            >
                <table class="min-w-full">
                    <thead class="bg-surface-muted text-left text-xs font-bold uppercase tracking-wide text-text-muted">
                        <tr>
                            <th class="px-5 py-3">Empresa / Razón Social</th>
                            <th class="px-5 py-3">Slug / ID sistema</th>
                            <th class="px-5 py-3">RTN / Identificación</th>
                            <th class="px-5 py-3 text-center">Estado</th>
                            <th class="px-5 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($companies as $company)
                            @php($initials = collect(explode(' ', $company->name))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode(''))
                            <tr class="transition {{ $activeCompany?->id === $company->id ? 'bg-brand/5' : 'bg-surface hover:bg-surface-muted/60' }}">
                                <td class="px-5 py-4">
                                    <div class="flex min-w-72 items-center gap-3">
                                        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-brand/10 text-sm font-black uppercase text-brand ring-1 ring-brand/15">
                                            {{ $initials ?: 'E' }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="truncate font-bold text-text">{{ $company->name }}</p>
                                                @if ($activeCompany?->id === $company->id)
                                                    <span class="rounded-full bg-brand px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white">En sesión</span>
                                                @endif
                                            </div>
                                            <p class="mt-1 text-xs text-text-muted">Entidad corporativa {{ $company->is_active ? 'operativa' : 'pausada' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 font-mono text-xs text-text-muted">
                                    <span class="rounded-lg bg-surface-muted px-2 py-1">{{ $company->slug }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm text-text-muted">
                                    <span class="font-mono font-semibold text-text">{{ $company->legal_id ?? 'Sin RTN' }}</span>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold {{ $company->is_active ? 'bg-success/10 text-success-strong' : 'bg-danger/10 text-danger-strong' }}">
                                        <span class="size-1.5 rounded-full {{ $company->is_active ? 'bg-success' : 'bg-danger' }}"></span>
                                        {{ $company->is_active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @can('update', $company)
                                            <x-ui.button :href="route('empresas.edit', $company)" variant="secondary" class="min-h-9 px-3 py-1 text-xs">
                                                Editar
                                            </x-ui.button>
                                        @endcan
                                        @can('activate', $company)
                                            <x-ui.loading-button
                                                wire:click="toggle({{ $company->id }})"
                                                target="toggle({{ $company->id }})"
                                                loading-label="Actualizando…"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-border bg-surface px-3 py-1 text-xs font-semibold text-text transition hover:bg-surface-muted"
                                            >
                                                {{ $company->is_active ? 'Desactivar' : 'Activar' }}
                                            </x-ui.loading-button>
                                        @endcan
                                        @can('delete', $company)
                                            <x-ui.loading-button
                                                wire:click="delete({{ $company->id }})"
                                                target="delete({{ $company->id }})"
                                                loading-label="Eliminando…"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-danger/20 bg-danger/10 px-3 py-1 text-xs font-semibold text-danger-strong transition hover:bg-danger/15"
                                                onclick="return confirm('¿Eliminar empresa?')"
                                            >
                                                Eliminar
                                            </x-ui.loading-button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-text-muted">No se encontraron empresas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pt-1">
            {{ $companies->links() }}
        </div>

        <x-ui.card>
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-lg font-bold text-text">Aislamiento estricto y gobernanza multi-entidad</p>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-text-muted">
                        Cada empresa opera con datos independientes. Esta pantalla administra solo el directorio corporativo; la parametrización fiscal avanzada y los reportes se mantienen fuera de esta slice visual.
                    </p>
                </div>
                <span class="inline-flex w-fit rounded-full bg-success/10 px-3 py-1 text-xs font-bold uppercase tracking-wide text-success-strong">Tenant-safe</span>
            </div>
        </x-ui.card>
    </div>
</div>
