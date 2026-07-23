<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f8f1ff_38%,_#ffffff_80%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Gestión empresarial
                    </p>
                    <h1 id="companies-heading" class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Empresas</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">Administrá empresas activas e inactivas con filtros rápidos y acciones de control.</p>
                </div>
                @can('create', App\Models\Company::class)
                    <a
                        href="/empresas/crear"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Nueva empresa
                    </a>
                @endcan
            </div>
        </header>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Búsqueda y filtros</h2>
                    <p class="mt-1 text-xs text-slate-600">Combiná nombre, slug y RTN para filtrar rápidamente.</p>
                </div>
                @if ($search !== '')
                    <button
                        type="button"
                        wire:click="$set('search', '')"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Limpiar filtros
                    </button>
                @endif
            </div>

            <label class="block" for="companies-search">
                <span class="mb-1 block text-xs font-medium text-slate-700">Buscar</span>
                <input
                    id="companies-search"
                    type="text"
                    wire:model.live="search"
                    placeholder="Buscar por nombre, slug o RTN..."
                    class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                >
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
            <div
                role="region"
                aria-labelledby="companies-heading"
                tabindex="0"
                class="overflow-x-auto"
            >
                <table class="min-w-full">
                    <thead class="bg-slate-50/90 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Nombre</th>
                            <th class="px-4 py-3">Slug</th>
                            <th class="px-4 py-3">RTN</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($companies as $company)
                            <tr class="border-t border-slate-200">
                                <td class="px-4 py-3.5 text-sm font-semibold text-slate-900">{{ $company->name }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $company->slug }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $company->legal_id }}</td>
                                <td class="px-4 py-3.5 text-sm">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium leading-5 {{ $company->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' }}">
                                        <span class="h-2 w-2 rounded-full {{ $company->is_active ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                        {{ $company->is_active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="flex flex-wrap gap-2">
                                        @can('update', $company)
                                            <a
                                                href="/empresas/{{ $company->id }}/editar"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100"
                                            >
                                                Editar
                                            </a>
                                        @endcan
                                        @can('activate', $company)
                                            <button
                                                wire:click="toggle({{ $company->id }})"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            >
                                                {{ $company->is_active ? 'Desactivar' : 'Activar' }}
                                            </button>
                                        @endcan
                                        @can('delete', $company)
                                            <button
                                                wire:click="delete({{ $company->id }})"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-rose-100 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
                                                onclick="return confirm('¿Eliminar empresa?')"
                                            >
                                                Eliminar
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">No se encontraron empresas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pt-1">
            {{ $companies->links() }}
        </div>
    </div>
</div>
