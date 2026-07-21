<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f8f1ff_38%,_#ffffff_80%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Control de carga
                    </p>
                    <h1 class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Archivos de marcas</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">
                        Revisá estado, fecha y consistencia de cada carga con filtros profesionales para auditoría rápida.
                    </p>
                </div>

                @can('create', App\Models\UploadedFile::class)
                    <a
                        href="{{ route('archivos.upload') }}"
                        class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Subir archivo
                    </a>
                @endcan
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" aria-label="Resumen de estados">
                <article class="rounded-2xl border border-slate-200 bg-white/95 px-4 py-3 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Total</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ array_sum($statusCounts) }}</p>
                </article>
                @foreach ($statusOptions as $key => $label)
                    @if ($key === 'all')
                        @continue
                    @endif
                    <article class="rounded-2xl border border-slate-200 bg-white/95 px-4 py-3 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $statusCounts[$key] ?? 0 }}</p>
                    </article>
                @endforeach
            </div>
        </header>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Búsqueda y filtros</h2>
                    <p class="mt-1 text-xs text-slate-600">Combiná texto, estado y rango para ubicar rápidamente la carga correcta.</p>
                </div>
                <button
                    type="button"
                    wire:click="clearFilters"
                    class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Limpiar filtros
                </button>
            </div>

            <div class="grid gap-3 lg:grid-cols-6">
                <label class="lg:col-span-2" for="files-search">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Buscar</span>
                    <input
                        id="files-search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Nombre de archivo o período"
                        class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                    >
                </label>

                <label for="files-status">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Estado</span>
                    <select id="files-status" wire:model.live="status" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label for="files-pay-period">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Período</span>
                    <select id="files-pay-period" wire:model.live="pay_period_id" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                        <option value="">Todos</option>
                        @foreach ($payPeriods as $payPeriod)
                            <option value="{{ $payPeriod->id }}">{{ $payPeriod->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label for="files-from-date">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Desde</span>
                    <input id="files-from-date" type="date" wire:model.live="from" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                </label>

                <label for="files-to-date">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Hasta</span>
                    <input id="files-to-date" type="date" wire:model.live="to" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                </label>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                @if ($search !== '')
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-indigo-700">
                        Búsqueda: <span class="ml-1 font-semibold">{{ $search }}</span>
                    </span>
                @endif
                @if ($status !== 'all')
                    <span class="inline-flex items-center rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-violet-700">
                        Estado: <span class="ml-1 font-semibold">{{ $statusOptions[$status] ?? $status }}</span>
                    </span>
                @endif
                @if ($pay_period_id)
                    @php($selectedPayPeriod = $payPeriods->firstWhere('id', (int) $pay_period_id))
                    <span class="inline-flex items-center rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-sky-700">
                        Período: <span class="ml-1 font-semibold">{{ $selectedPayPeriod->name ?? $pay_period_id }}</span>
                    </span>
                @endif
                @if ($from !== '')
                    <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-emerald-700">
                        Desde: <span class="ml-1 font-semibold">{{ $from }}</span>
                    </span>
                @endif
                @if ($to !== '')
                    <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-amber-700">
                        Hasta: <span class="ml-1 font-semibold">{{ $to }}</span>
                    </span>
                @endif
                @if ($search === '' && $status === 'all' && empty($pay_period_id) && $from === '' && $to === '')
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
                            <th class="px-4 py-3">Archivo</th>
                            <th class="px-4 py-3">Período</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Registros</th>
                            <th class="px-4 py-3">Subida</th>
                            <th class="px-4 py-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($files as $file)
                            <tr class="border-t border-slate-200">
                                <td class="px-4 py-3.5">
                                    <p class="max-w-[24rem] truncate font-semibold text-slate-900" title="{{ $file->original_name }}">{{ $file->original_name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $file->stored_name }}</p>
                                </td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $file->payPeriod?->name ?? 'Sin período' }}</td>
                                <td class="px-4 py-3.5 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $this->statusClasses($file->status) }}">
                                        {{ $this->statusLabel($file->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ number_format($file->raw_marks_count) }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $file->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex flex-wrap gap-2">
                                        <a
                                            href="{{ route('archivos.show', $file) }}"
                                            class="inline-flex min-h-9 items-center rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100"
                                        >
                                            Ver detalle
                                        </a>
                                        @can('manage', $file)
                                            <a
                                                href="{{ route('archivos.upload', ['pay_period_id' => $file->pay_period_id]) }}"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            >
                                                Reemplazar
                                            </a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No se encontraron archivos para los filtros aplicados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pt-1">
            {{ $files->links() }}
        </div>
    </div>
</div>
