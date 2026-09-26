@php
    $visibleUsers = $users->getCollection();
    $activeVisibleUsers = $visibleUsers->where('is_active', true)->count();
    $roleNames = $visibleUsers
        ->map(fn ($user) => $user->getRoleNames()->first() ?? 'Sin rol')
        ->filter()
        ->unique()
        ->values();
@endphp

<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#eef4ff_0%,_#f8fafc_42%,_#ffffff_82%)] px-4 py-8 sm:px-6 lg:px-8" data-users-index="workspace">
    <div class="mx-auto max-w-7xl space-y-6">
        <header class="rounded-3xl border border-slate-200/80 bg-white/95 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-indigo-700">
                            Gestión de Usuarios / Control de Acceso y Permisos Multi-Empresa
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] text-emerald-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            RBAC activo
                        </span>
                    </div>
                    <div>
                        <h1 id="users-heading" class="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Usuarios del Sistema</h1>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Administrá cuentas de acceso, roles corporativos y asignación de empresas con el alcance real definido por permisos y tenant activo.
                        </p>
                    </div>
                </div>

                @can('create', App\Models\User::class)
                    <a
                        href="{{ route('usuarios.create') }}"
                        class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        + Nuevo usuario
                    </a>
                @endcan
            </div>
        </header>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de usuarios">
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Usuarios registrados</p>
                <p class="mt-3 text-3xl font-black text-slate-950">{{ $users->total() }}</p>
                <p class="mt-2 text-sm text-slate-600">Total del resultado autorizado actual.</p>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Usuarios visibles</p>
                <p class="mt-3 text-3xl font-black text-slate-950">{{ $visibleUsers->count() }}</p>
                <p class="mt-2 text-sm text-slate-600">Filas mostradas en esta página.</p>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Activos visibles</p>
                <p class="mt-3 text-3xl font-black text-emerald-700">{{ $activeVisibleUsers }}</p>
                <p class="mt-2 text-sm text-slate-600">Según el estado guardado de cada cuenta.</p>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Roles visibles</p>
                <p class="mt-3 text-3xl font-black text-indigo-700">{{ $roleNames->count() }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ $roleNames->join(', ') ?: 'Sin roles visibles' }}</p>
            </article>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" data-users-section="filters">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-xl">
                    <h2 class="text-sm font-bold uppercase tracking-[0.14em] text-slate-500">Búsqueda</h2>
                    <p class="mt-1 text-sm text-slate-600">Buscá por nombre o correo electrónico. El alcance de empresa se conserva en el componente Livewire.</p>
                </div>
                @if ($search !== '')
                    <button
                        type="button"
                        wire:click="$set('search', '')"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Limpiar búsqueda
                    </button>
                @endif
            </div>

            <label class="mt-4 block" for="users-search">
                <span class="mb-1 block text-sm font-semibold text-slate-700">Buscar usuarios</span>
                <input
                    id="users-search"
                    type="text"
                    wire:model.live="search"
                    placeholder="Buscar por nombre o correo..."
                    class="h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                >
            </label>

            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                @if ($search !== '')
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 font-semibold text-indigo-700">
                        Búsqueda: <span class="ml-1">{{ $search }}</span>
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 font-semibold text-slate-600">
                        Sin búsqueda activa
                    </span>
                @endif
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" data-users-section="directory">
            <div class="border-b border-slate-200 bg-slate-50/70 px-5 py-4">
                <h2 class="text-sm font-bold uppercase tracking-[0.14em] text-slate-600">Directorio de acceso</h2>
                <p class="mt-1 text-sm text-slate-600">Cuentas autorizadas para el contexto actual.</p>
            </div>

            <div class="overflow-x-auto" role="region" aria-labelledby="users-heading" tabindex="0">
                <table class="min-w-full text-left">
                    <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-5 py-3">Usuario / Nombre</th>
                            <th class="px-5 py-3">Empresa asignada</th>
                            <th class="px-5 py-3">Rol</th>
                            <th class="px-5 py-3 text-center">Estado</th>
                            <th class="px-5 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($users as $user)
                            @php
                                $initials = collect(explode(' ', trim($user->name)))
                                    ->filter()
                                    ->take(2)
                                    ->map(fn ($part) => mb_substr($part, 0, 1))
                                    ->join('');
                                $roleName = $user->getRoleNames()->first() ?? 'Sin rol';
                            @endphp
                            <tr class="transition hover:bg-slate-50/80">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-sm font-black uppercase text-white shadow-sm">
                                            {{ $initials ?: 'US' }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-950">{{ $user->name }}</p>
                                            <p class="truncate text-sm text-slate-600">{{ $user->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">
                                    <span class="inline-flex items-center rounded-xl bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                        {{ $user->company?->name ?? 'Todas las empresas' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full {{ $roleName === 'super_admin' ? 'bg-indigo-50 text-indigo-700 ring-indigo-200' : 'bg-blue-50 text-blue-700 ring-blue-200' }} px-3 py-1 text-xs font-bold ring-1">
                                        {{ $roleName }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold {{ $user->is_active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' }}">
                                        <span class="h-2 w-2 rounded-full {{ $user->is_active ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                        {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @can('update', $user)
                                            <a
                                                href="{{ route('usuarios.edit', $user) }}"
                                                class="inline-flex min-h-9 items-center rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                            >
                                                Editar
                                            </a>

                                            @if (! $user->is(auth()->user()) && $user->is_active)
                                                <button
                                                    type="button"
                                                    wire:click="deactivate({{ $user->id }})"
                                                    wire:confirm="¿Desactivar a {{ $user->name }}? Se revocarán sus sesiones activas."
                                                    class="inline-flex min-h-9 items-center rounded-xl border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 transition hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2"
                                                >
                                                    Desactivar
                                                </button>
                                            @endif
                                        @endcan

                                        @can('delete', $user)
                                            @if (! $user->is(auth()->user()))
                                                <button
                                                    type="button"
                                                    wire:click="delete({{ $user->id }})"
                                                    wire:confirm="¿Eliminar a {{ $user->name }}? La cuenta dejará de aparecer y sus sesiones se revocarán."
                                                    class="inline-flex min-h-9 items-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-bold text-rose-700 transition hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2"
                                                >
                                                    Eliminar
                                                </button>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-500">
                                    No se encontraron usuarios para el alcance actual.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 bg-slate-50/70 px-5 py-4">
                {{ $users->links() }}
            </div>
        </section>

        <section class="rounded-3xl border border-indigo-100 bg-indigo-50/70 p-5 text-sm text-indigo-950 shadow-sm">
            <h2 class="font-bold">Matriz de Control de Acceso Basado en Roles</h2>
            <p class="mt-1 max-w-4xl leading-6 text-indigo-900/80">
                Los permisos efectivos siguen definidos por las policies y roles existentes. Esta vista no cambia reglas de acceso ni alcance multiempresa.
            </p>
        </section>
    </div>
</div>
