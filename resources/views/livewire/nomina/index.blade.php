<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <header class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600">Gestión de nómina</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">Períodos de nómina</h1>
                <p class="mt-3 text-sm leading-6 text-slate-600 sm:text-base">
                    Definí las fechas del período y continuá con la carga del archivo de marcas. La carga valida los datos; no procesa la nómina automáticamente.
                </p>
            </div>

            @can('create', App\Models\PayPeriod::class)
                <button
                    id="create-period-trigger"
                    type="button"
                    wire:click="openCreateForm"
                    aria-expanded="{{ $showCreateForm ? 'true' : 'false' }}"
                    aria-controls="create-period-panel"
                    class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Crear período
                </button>
            @endcan
        </div>

        <div class="mt-7 border-t border-slate-100 pt-5" aria-label="Etapas del flujo de nómina">
            <p class="mb-3 text-sm font-medium text-slate-700">Estás en: <span class="font-semibold text-indigo-700">1. Períodos</span></p>
            <ol class="grid gap-3 text-sm sm:grid-cols-3">
                <li class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-indigo-900">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-indigo-600">Paso 1</span>
                    Crear o elegir período
                </li>
                <li class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-700">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Paso 2</span>
                    Cargar y validar marcas
                </li>
                <li class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-700">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Paso 3</span>
                    Revisar el resultado
                </li>
            </ol>
        </div>
    </header>

    @if ($showCreateForm)
        <section id="create-period-panel" aria-labelledby="create-period-heading" class="mt-6 rounded-2xl border border-indigo-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">Nuevo período</p>
                <h2 id="create-period-heading" class="mt-1 text-xl font-bold text-slate-950">Definí el rango de fechas</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Las fechas de inicio y fin se incluyen dentro del período.</p>
            </div>

            <form id="create-period-form" wire:submit="store" class="mt-6 grid gap-5 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <label for="period-name" class="block text-sm font-semibold text-slate-800">Nombre del período</label>
                    <p id="period-name-hint" class="mt-1 text-sm text-slate-500">Usá un nombre que puedas reconocer en la lista.</p>
                    <input
                        id="period-name"
                        type="text"
                        wire:model="name"
                        maxlength="120"
                        required
                        autocomplete="off"
                        aria-describedby="period-name-hint @error('name') period-name-error @enderror"
                        @error('name') aria-invalid="true" @else aria-invalid="false" @enderror
                        class="mt-2 block min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                    >
                    @error('name')
                        <p id="period-name-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="period-start-date" class="block text-sm font-semibold text-slate-800">Fecha de inicio</label>
                    <input
                        id="period-start-date"
                        type="date"
                        wire:model="start_date"
                        required
                        @error('start_date') aria-describedby="period-start-date-error" aria-invalid="true" @else aria-invalid="false" @enderror
                        class="mt-2 block min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                    >
                    @error('start_date')
                        <p id="period-start-date-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="period-end-date" class="block text-sm font-semibold text-slate-800">Fecha de fin</label>
                    <input
                        id="period-end-date"
                        type="date"
                        wire:model="end_date"
                        required
                        @error('end_date') aria-describedby="period-end-date-error" aria-invalid="true" @else aria-invalid="false" @enderror
                        class="mt-2 block min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                    >
                    @error('end_date')
                        <p id="period-end-date-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end lg:col-span-2">
                    <button type="button" wire:click="closeCreateForm" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Cancelar
                    </button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="store" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="store">Crear y continuar</span>
                        <span wire:loading wire:target="store">Creando período...</span>
                    </button>
                </div>
            </form>
        </section>
    @endif

    @if (! $hasCompany)
        <div role="status" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-900">
            Seleccioná una empresa activa para ver o crear sus períodos de nómina.
        </div>
    @else
        <section aria-labelledby="period-list-heading" class="mt-8">
            <div class="mb-4">
                <h2 id="period-list-heading" class="text-xl font-bold text-slate-950">Períodos existentes</h2>
                <p class="mt-1 text-sm text-slate-600">El estado muestra hasta dónde avanzó cada período.</p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="max-w-full overflow-x-auto">
                    <table class="min-w-[760px] w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-left font-semibold">Nombre</th>
                                <th scope="col" class="px-5 py-3 text-left font-semibold">Inicio</th>
                                <th scope="col" class="px-5 py-3 text-left font-semibold">Fin</th>
                                <th scope="col" class="px-5 py-3 text-left font-semibold">Estado</th>
                                <th scope="col" class="px-5 py-3 text-left font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($payPeriods as $payPeriod)
                                @php
                                    $statusClass = match ($payPeriod->status) {
                                        'ready', 'processed' => 'bg-emerald-100 text-emerald-800',
                                        'validating', 'processing' => 'bg-blue-100 text-blue-800',
                                        'approved' => 'bg-violet-100 text-violet-800',
                                        'exported' => 'bg-slate-100 text-slate-700',
                                        'validation_failed', 'cancelled' => 'bg-red-100 text-red-800',
                                        default => 'bg-amber-100 text-amber-800',
                                    };
                                    $statusLabel = match ($payPeriod->status) {
                                        'draft' => 'Borrador',
                                        'uploaded' => 'Archivo cargado',
                                        'validating' => 'Validando',
                                        'validation_failed' => 'Validación con errores',
                                        'ready' => 'Listo',
                                        'processing' => 'Procesando',
                                        'processed' => 'Procesado',
                                        'approved' => 'Aprobado',
                                        'exported' => 'Exportado',
                                        'cancelled' => 'Cancelado',
                                        default => 'Estado pendiente',
                                    };
                                @endphp
                                <tr>
                                    <td class="px-5 py-4 font-medium text-slate-900">{{ $payPeriod->name ?? $payPeriod->slug }}</td>
                                    <td class="px-5 py-4 text-slate-600">{{ $payPeriod->start_date->format('d/m/Y') }}</td>
                                    <td class="px-5 py-4 text-slate-600">{{ $payPeriod->end_date->format('d/m/Y') }}</td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-4">
                                            @if ($payPeriod->canUploadFiles())
                                                <a href="{{ route('archivos.upload', ['pay_period_id' => $payPeriod->id]) }}" class="font-semibold text-indigo-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Cargar marcas</a>
                                            @endif
                                            <a href="{{ route('nomina.revisar', $payPeriod) }}" class="font-semibold text-slate-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Revisar</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-10 text-center text-slate-500">Todavía no hay períodos de nómina.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">
                {{ $payPeriods->links() }}
            </div>
        </section>
    @endif
</div>
