<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f8f1ff_38%,_#ffffff_80%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Gestión de usuarios
                    </p>
                    <h1 class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Usuarios</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">Filtrá y administrá usuarios con visibilidad de empresa, rol y estado.</p>
                </div>
                @can('create', App\Models\User::class)
                    <a
                        href="/usuarios/crear"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Nuevo usuario
                    </a>
                @endcan
            </div>
        </header>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Búsqueda y filtros</h2>
                    <p class="mt-1 text-xs text-slate-600">Buscá por nombre o correo para localizar rápidamente una cuenta.</p>
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

            <label class="block" for="users-search">
                <span class="mb-1 block text-xs font-medium text-slate-700">Buscar</span>
                <input
                    id="users-search"
                    type="text"
                    wire:model.live="search"
                    placeholder="Buscar por nombre o correo..."
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
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-slate-50/90 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Nombre</th>
                            <th class="px-4 py-3">Correo</th>
                            <th class="px-4 py-3">Empresa</th>
                            <th class="px-4 py-3">Rol</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr class="border-t border-slate-200">
                                <td class="px-4 py-3.5 text-sm font-semibold text-slate-900">{{ $user->name }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $user->email }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $user->company?->name ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $user->getRoleNames()->first() }}</td>
                                <td class="px-4 py-3.5 text-sm">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium leading-5 {{ $user->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' }}">
                                        <span class="h-2 w-2 rounded-full {{ $user->is_active ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                        {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    @can('update', $user)
                                        <a
                                            href="/usuarios/{{ $user->id }}/editar"
                                            class="inline-flex min-h-9 items-center rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100"
                                        >
                                            Editar
                                        </a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No se encontraron usuarios.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pt-1">
            {{ $users->links() }}
        </div>
    </div>
</div>
