@php
    $visibleHolidays = $holidays->getCollection();
    $activeVisibleHolidays = $visibleHolidays->where('is_active', true);
    $nextActiveHoliday = $activeVisibleHolidays
        ->filter(fn ($holiday) => $holiday->date->isToday() || $holiday->date->isFuture())
        ->sortBy('date')
        ->first();
@endphp

<div class="relative isolate min-h-screen bg-[radial-gradient(circle_at_top,_#eef4ff_0%,_#f8fafc_42%,_#ffffff_82%)] px-4 py-8 sm:px-6 lg:px-8" data-holidays-index="workspace">
    <x-ui.loading-overlay target="save,delete" message="Validando y actualizando el calendario…" />

    <div
        x-data="{ hideCompanyToastTimer: null }"
        x-show="$wire.showCompanyToast"
        x-effect="
            if (! $wire.showCompanyToast) {
                clearTimeout(hideCompanyToastTimer);
                hideCompanyToastTimer = null;

                return;
            }

            clearTimeout(hideCompanyToastTimer);
            hideCompanyToastTimer = setTimeout(() => $wire.call('hideCompanyToast'), 3800);
        "
        x-transition.opacity
        x-cloak
        class="fixed right-4 top-4 z-[1000] flex max-w-sm rounded-3xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 shadow-sm"
        role="alert"
        aria-live="assertive"
    >
        <div class="flex items-start justify-between gap-3">
            <p class="font-medium">{{ $companyToastMessage }}</p>
            <button
                type="button"
                wire:click="hideCompanyToast"
                class="rounded-full border border-amber-200 bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900 transition hover:bg-amber-200"
            >
                Cerrar
            </button>
        </div>
    </div>

    <div class="mx-auto max-w-7xl space-y-6">
        <header class="rounded-3xl border border-slate-200/80 bg-white/95 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-indigo-700">
                            Calendario laboral
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] text-emerald-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Sincronización nómina
                        </span>
                    </div>
                    <div>
                        <h1 id="holidays-heading" class="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Feriados y Días Inhábiles</h1>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Gestión de días no laborables por empresa. Cada cambio usa el calendario real de asistencia y mantiene las validaciones de períodos bloqueados.
                        </p>
                    </div>
                </div>

                @can('holidays.manage')
                    <button
                        wire:click="openCreateModal"
                        @disabled(!$hasCompany)
                        class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500 hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Nuevo feriado
                    </button>
                @endcan
            </div>
        </header>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de feriados">
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Feriados registrados</p>
                <p class="mt-3 text-3xl font-black text-slate-950">{{ $holidays->total() }}</p>
                <p class="mt-2 text-sm text-slate-600">Total autorizado para el alcance actual.</p>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Activos visibles</p>
                <p class="mt-3 text-3xl font-black text-emerald-700">{{ $activeVisibleHolidays->count() }}</p>
                <p class="mt-2 text-sm text-slate-600">Feriados activos en esta página.</p>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:col-span-2 xl:col-span-1">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Próximo feriado activo</p>
                @if ($nextActiveHoliday)
                    <p class="mt-3 text-lg font-black text-slate-950">{{ $nextActiveHoliday->date->isoFormat('D [de] MMMM') }}</p>
                    <p class="mt-2 text-sm font-semibold text-indigo-700">{{ $nextActiveHoliday->name }}</p>
                @else
                    <p class="mt-3 text-lg font-black text-slate-950">Sin próximos activos</p>
                    <p class="mt-2 text-sm text-slate-600">No hay fechas futuras activas visibles.</p>
                @endif
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Página visible</p>
                <p class="mt-3 text-3xl font-black text-indigo-700">{{ $visibleHolidays->count() }}</p>
                <p class="mt-2 text-sm text-slate-600">Filas mostradas tras búsqueda/paginación.</p>
            </article>
        </section>

        @if (! $hasCompany)
            <section class="rounded-3xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 shadow-sm">
                <h2 class="font-bold">Seleccioná una empresa para gestionar feriados.</h2>
                <p class="mt-1 leading-6">El calendario es tenant-scoped. La acción de crear permanece disponible para mostrar el aviso guiado, pero no guarda sin empresa activa.</p>
            </section>
        @else
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" data-holidays-section="filters">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-xl">
                        <h2 class="text-sm font-bold uppercase tracking-[0.14em] text-slate-500">Búsqueda</h2>
                        <p class="mt-1 text-sm text-slate-600">Buscá por nombre o descripción. El año, estado y empresa siguen determinados por los datos reales del componente.</p>
                    </div>
                    @if ($search !== '')
                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                        >
                            Limpiar búsqueda
                        </button>
                    @endif
                </div>

                <label class="mt-4 block" for="holidays-search">
                    <span class="mb-1 block text-sm font-semibold text-slate-700">Buscar feriados</span>
                    <input
                        id="holidays-search"
                        type="text"
                        wire:model.live="search"
                        placeholder="Buscar por nombre o descripción..."
                        class="h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                    />
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

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" data-holidays-section="registry">
                <div class="border-b border-slate-200 bg-slate-50/70 px-5 py-4">
                    <h2 class="text-sm font-bold uppercase tracking-[0.14em] text-slate-600">Registro de feriados</h2>
                    <p class="mt-1 text-sm text-slate-600">Fechas administradas por el calendario laboral de la empresa activa.</p>
                </div>

                <div class="overflow-x-auto" role="region" aria-labelledby="holidays-heading" tabindex="0">
                    <table class="min-w-full text-left">
                        <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-5 py-3">Fecha / Feriado</th>
                                <th class="px-5 py-3">Descripción</th>
                                <th class="px-5 py-3 text-center">Estado</th>
                                <th class="px-5 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @forelse ($holidays as $holiday)
                                <tr class="transition hover:bg-slate-50/80">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-2xl {{ $holiday->is_active ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-500' }} shadow-sm">
                                                <span class="text-[10px] font-black uppercase tracking-[0.12em]">{{ $holiday->date->format('M') }}</span>
                                                <span class="text-lg font-black leading-none">{{ $holiday->date->format('d') }}</span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold text-slate-950">{{ $holiday->name }}</p>
                                                <p class="mt-1 text-xs font-semibold text-slate-500">{{ $holiday->date->format('Y-m-d') }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-sm leading-6 text-slate-600">
                                        {{ $holiday->description ?? 'Sin descripción registrada' }}
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        @can('holidays.manage')
                                            <x-ui.loading-button
                                                wire:click="toggle({{ $holiday->id }})"
                                                target="toggle({{ $holiday->id }})"
                                                loading-label="Actualizando…"
                                                class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold {{ $holiday->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-600' }}"
                                            >
                                                {{ $holiday->is_active ? 'Activo' : 'Inactivo' }}
                                            </x-ui.loading-button>
                                        @else
                                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold {{ $holiday->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-600' }}">
                                                {{ $holiday->is_active ? 'Activo' : 'Inactivo' }}
                                            </span>
                                        @endcan
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        @can('holidays.manage')
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <x-ui.loading-button
                                                    wire:click="edit({{ $holiday->id }})"
                                                    target="edit({{ $holiday->id }})"
                                                    loading-label="Abriendo…"
                                                    class="inline-flex min-h-9 items-center rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100"
                                                >
                                                    Editar
                                                </x-ui.loading-button>
                                                <x-ui.loading-button
                                                    wire:click="confirmDelete({{ $holiday->id }})"
                                                    target="confirmDelete({{ $holiday->id }})"
                                                    loading-label="Abriendo…"
                                                    class="inline-flex min-h-9 items-center rounded-xl border border-rose-100 bg-rose-50 px-3 py-1 text-xs font-bold text-rose-700 transition hover:bg-rose-100"
                                                >
                                                    Eliminar
                                                </x-ui.loading-button>
                                            </div>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-sm text-slate-500">Sin feriados registrados.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-200 bg-slate-50/70 px-5 py-4">
                    {{ $holidays->links() }}
                </div>
            </section>

            <section class="rounded-3xl border border-indigo-100 bg-indigo-50/70 p-5 text-sm text-indigo-950 shadow-sm">
                <h2 class="font-bold">Integración con nómina y asistencia</h2>
                <p class="mt-1 max-w-4xl leading-6 text-indigo-900/80">
                    El calendario real se usa por los servicios de asistencia y nómina. Las reglas legales, recargos y bloqueos de períodos se mantienen en backend.
                </p>
            </section>
        @endif
    </div>

    @if ($showCreateModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="holiday-modal-title"
            x-data
            @keydown.escape.window="$wire.closeCreateModal()"
        >
            <div class="relative w-full max-w-2xl overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-[0_25px_60px_-15px_rgba(30,27,75,0.25)]">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-b from-slate-50/70 to-white px-6 pb-5 pt-6 sm:px-7 sm:pt-7">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-indigo-100 bg-indigo-50 text-indigo-600 shadow-sm" aria-hidden="true">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M12 14l1.5 2 3-3" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 id="holiday-modal-title" class="text-xl font-black tracking-tight text-slate-950">
                                    {{ $editingId ? 'Editar Feriado o Día Inhábil' : 'Registrar Feriado o Día Inhábil' }}
                                </h2>
                                <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700">
                                    Calendario real
                                </span>
                            </div>
                            <p class="mt-1 max-w-lg text-xs leading-5 text-slate-500">
                                Configurá fecha, nombre, descripción y estado dentro del calendario real.
                            </p>
                        </div>
                    </div>
                    <button
                        wire:click="closeCreateModal"
                        type="button"
                        aria-label="Cerrar ventana emergente"
                        class="rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-6 px-6 py-6 sm:px-7">
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label for="holiday-date" class="block text-xs font-black uppercase tracking-[0.14em] text-slate-700">
                                Fecha del feriado <span class="text-indigo-600">*</span>
                            </label>
                            <span class="text-[11px] font-semibold text-slate-400">Requerida para el calendario laboral</span>
                        </div>
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-indigo-600" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                            <input
                                id="holiday-date"
                                type="date"
                                wire:model="formDate"
                                class="w-full rounded-xl border border-slate-200 bg-slate-50/70 py-2.5 pl-11 pr-4 text-sm font-semibold text-slate-900 shadow-sm outline-none transition hover:bg-white focus:border-indigo-600 focus:bg-white focus:ring-4 focus:ring-indigo-500/10"
                            />
                        </div>
                        @error('formDate') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                        <div class="flex items-center gap-2 rounded-xl border border-indigo-100 bg-indigo-50/70 px-3 py-2 text-xs font-medium text-indigo-950">
                            <svg class="h-4 w-4 shrink-0 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span>Esta fecha se guarda por empresa activa y queda disponible para asistencia y nómina.</span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label for="holiday-name" class="block text-xs font-black uppercase tracking-[0.14em] text-slate-700">
                                Nombre / denominación oficial <span class="text-indigo-600">*</span>
                            </label>
                            <span class="text-[11px] text-slate-400">Visible en listados internos</span>
                        </div>
                        <input
                            id="holiday-name"
                            type="text"
                            wire:model="formName"
                            placeholder="Ej. Día de la Independencia"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm outline-none transition hover:bg-white focus:border-indigo-600 focus:bg-white focus:ring-4 focus:ring-indigo-500/10"
                        />
                        @error('formName') <p class="text-sm text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label for="holiday-description" class="block text-xs font-black uppercase tracking-[0.14em] text-slate-700">
                                Descripción
                            </label>
                            <span class="text-[11px] text-slate-400">Opcional</span>
                        </div>
                        <textarea
                            id="holiday-description"
                            wire:model="formDescription"
                            rows="3"
                            placeholder="Agregá una nota operativa para el equipo de nómina."
                            class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition hover:bg-white focus:border-indigo-600 focus:bg-white focus:ring-4 focus:ring-indigo-500/10"
                        ></textarea>
                    </div>

                    <label class="flex items-start justify-between gap-4 rounded-2xl border border-indigo-200 bg-gradient-to-r from-indigo-50/70 via-white to-slate-50 p-4 shadow-sm">
                        <span class="flex items-start gap-3">
                            <input id="holiday-active" type="checkbox" wire:model="formIsActive" class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                            <span>
                                <span class="block text-xs font-black text-slate-900">Feriado activo en calendario</span>
                                <span class="mt-1 block text-xs leading-5 text-slate-500">Los feriados activos quedan disponibles para los servicios reales de asistencia y nómina.</span>
                            </span>
                        </span>
                        <span class="inline-flex shrink-0 items-center rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-emerald-800">
                            Vigente
                        </span>
                    </label>
                </div>

                <div class="flex flex-col gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>Las validaciones y permisos se resuelven en backend.</span>
                    </div>
                    <div class="flex w-full items-center justify-end gap-3 sm:w-auto">
                        <button
                            wire:click="closeCreateModal"
                            type="button"
                            class="inline-flex min-h-10 w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 shadow-sm transition hover:bg-slate-100 sm:w-auto"
                        >Cancelar</button>
                        <x-ui.loading-button
                            wire:click="save"
                            type="button"
                            target="save"
                            loading-label="Guardando…"
                            class="inline-flex min-h-10 w-full items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-xs font-black text-white shadow-md shadow-indigo-600/30 transition hover:bg-indigo-700 sm:w-auto"
                        >Guardar feriado</x-ui.loading-button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($confirmingDelete)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-sm rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
                <h2 class="text-lg font-black text-slate-900">Eliminar feriado</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Confirmá que querés eliminar este feriado. Si afecta períodos bloqueados, el servicio rechazará la operación.</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        wire:click="$set('confirmingDelete', false)"
                        type="button"
                        class="inline-flex min-h-10 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                    >Cancelar</button>
                    <x-ui.loading-button
                        wire:click="delete"
                        type="button"
                        target="delete"
                        loading-label="Eliminando…"
                        class="inline-flex min-h-10 items-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-rose-700"
                    >Eliminar</x-ui.loading-button>
                </div>
            </div>
        </div>
    @endif
</div>
