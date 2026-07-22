<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f1f5ff_38%,_#ffffff_78%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Detalle de carga
                    </p>
                    <h1 class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">{{ $uploadedFile->original_name }}</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">
                        Revisá la trazabilidad de registros, métricas de validación y acciones permitidas para esta carga.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a
                        href="{{ route('archivos.index') }}"
                        class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Volver
                    </a>

                    @if ($uploadedFile->payPeriod && auth()->user()?->can('marks.manage') && auth()->user()?->can('view', $uploadedFile->payPeriod))
                        <a
                            href="{{ route('nomina.revisar', ['payPeriod' => $uploadedFile->payPeriod, 'uploaded_file_id' => $uploadedFile->id]) }}"
                            class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                        >
                            Revisar y corregir
                        </a>
                    @endif

                    <a
                        href="{{ route('archivos.report', ['uploadedFile' => $uploadedFile]) }}"
                        class="inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Descargar reporte
                    </a>

                    @can('manage', $uploadedFile)
                        <a
                            href="{{ route('archivos.upload', ['pay_period_id' => $uploadedFile->pay_period_id]) }}"
                            class="inline-flex min-h-11 items-center rounded-xl border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100"
                        >
                            Reemplazar archivo
                        </a>
                    @endcan
                </div>
            </div>
        </header>

        @php
            $summaryTotal = (int) ($summary['total'] ?? 0);
            $summaryValid = (int) ($summary['valid'] ?? 0);
            $summaryDuplicate = (int) ($summary['duplicate'] ?? 0);
            $summaryOutOfPeriod = (int) ($summary['out_of_period'] ?? 0);
            $summaryUnknown = (int) ($summary['unknown_employee'] ?? 0);
            $summaryInvalid = (int) ($summary['invalid_row'] ?? 0);
            $alertCount = $summaryUnknown + $summaryInvalid;
            $duplicateRate = $summaryTotal > 0 ? round(($summaryDuplicate / $summaryTotal) * 100, 1) : 0;
            $outOfPeriodRate = $summaryTotal > 0 ? round(($summaryOutOfPeriod / $summaryTotal) * 100, 1) : 0;
            $unknownRate = $summaryTotal > 0 ? round(($summaryUnknown / $summaryTotal) * 100, 1) : 0;
            $invalidRate = $summaryTotal > 0 ? round(($summaryInvalid / $summaryTotal) * 100, 1) : 0;
            $validRate = max(0, 100 - $duplicateRate - $outOfPeriodRate - $unknownRate - $invalidRate);
            $statusFilterLabel = $recordStatusOptions[$status] ?? 'Todos';
        @endphp

        <section class="grid gap-4 lg:grid-cols-[1.7fr_1fr_1fr]">
            <article class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Contexto operativo</h2>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Estado del archivo</dt>
                        <dd class="mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $this->fileStatusClass($uploadedFile->status) }}">
                            {{ $this->fileStatusLabel($uploadedFile->status) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Período</dt>
                        <dd class="mt-1 text-slate-900">{{ $uploadedFile->payPeriod?->name ?? 'Sin período' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Total de registros</dt>
                        <dd class="mt-1 text-2xl font-black text-slate-900">{{ number_format($summaryTotal) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Fecha de carga</dt>
                        <dd class="mt-1 text-slate-900">{{ $uploadedFile->created_at->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Subido por</dt>
                        <dd class="mt-1 text-slate-900">{{ $uploadedFile->user?->name ?? 'Sistema' }}</dd>
                    </div>
                </dl>
            </article>

            <article class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Estado de validación</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li>
                        <div class="flex items-center justify-between text-slate-700"><span>Válidos</span><strong class="font-semibold text-emerald-700">{{ $summaryValid }}</strong></div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $summaryTotal > 0 ? $validRate : 0 }}%"></div></div>
                    </li>
                    <li>
                        <div class="flex items-center justify-between text-slate-700"><span>Duplicados</span><strong class="font-semibold text-amber-700">{{ $summaryDuplicate }}</strong></div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-slate-100"><div class="h-full rounded-full bg-amber-400" style="width: {{ $duplicateRate }}%"></div></div>
                    </li>
                    <li>
                        <div class="flex items-center justify-between text-slate-700"><span>Fuera de período</span><strong class="font-semibold text-orange-700">{{ $summaryOutOfPeriod }}</strong></div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-slate-100"><div class="h-full rounded-full bg-orange-400" style="width: {{ $outOfPeriodRate }}%"></div></div>
                    </li>
                </ul>
            </article>

            <article class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Alertas críticas</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li>
                        <div class="flex items-center justify-between text-slate-700"><span>Empleados desconocidos</span><strong class="font-semibold text-rose-700">{{ $summaryUnknown }}</strong></div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-slate-100"><div class="h-full rounded-full bg-rose-500" style="width: {{ $unknownRate }}%"></div></div>
                    </li>
                    <li>
                        <div class="flex items-center justify-between text-slate-700"><span>Filas inválidas</span><strong class="font-semibold text-rose-700">{{ $summaryInvalid }}</strong></div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-slate-100"><div class="h-full rounded-full bg-rose-600" style="width: {{ $invalidRate }}%"></div></div>
                    </li>
                </ul>
                <p class="mt-3 rounded-xl border border-rose-100 bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ $alertCount }} alertas requieren revisión para publicar con confianza.</p>
            </article>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">Registros de archivo</h2>
                    <p class="mt-1 text-sm text-slate-600">Buscá por empleado, fila o estado para analizar consistencia. Este detalle conserva la evidencia; las correcciones se realizan en la revisión de nómina.</p>
                </div>
                <button
                    type="button"
                    wire:click="clearFilters"
                    class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Limpiar filtros
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-4">
                <label class="sm:col-span-2" for="record-search">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Buscar registro</span>
                    <input
                        id="record-search"
                        type="text"
                        wire:model.live.debounce.250ms="search"
                        placeholder="Empleado o fila"
                        class="h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"
                    >
                </label>

                <label for="record-status">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Estado</span>
                    <select id="record-status" wire:model.live="status" class="h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
                        @foreach ($recordStatusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Filtro activo</p>
                    <p class="mt-1 text-sm font-semibold text-slate-900">{{ $statusFilterLabel }}</p>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                @if ($search !== '')
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-indigo-700">
                        Buscando: <span class="ml-1 font-semibold">{{ $search }}</span>
                    </span>
                @endif
                @if ($status !== 'all')
                    <span class="inline-flex items-center rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-violet-700">
                        Estado: <span class="ml-1 font-semibold">{{ $statusFilterLabel }}</span>
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-slate-600">
                        Estado: Todos
                    </span>
                @endif
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-slate-50/90 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Fila</th>
                            <th class="px-4 py-3">Empleado</th>
                            <th class="px-4 py-3">Fecha y hora</th>
                            <th class="px-4 py-3">Origen</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Notas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($records as $record)
                            <tr class="border-t border-slate-200">
                                <td class="px-4 py-3.5 text-sm font-medium text-slate-900">{{ $record->row_number }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-900">{{ $record->employee_external_id }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $record->event_at->format('d/m/Y H:i:s') }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $record->source }}</td>
                                <td class="px-4 py-3.5 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $this->recordStatusClass($record->status) }}">
                                        {{ $this->recordStatusLabel($record->status) }}
                                    </span>
                                </td>
                                <td class="max-w-[22rem] px-4 py-3.5 text-sm text-slate-700">{{ $record->notes ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No se encontraron registros.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pt-1">
            {{ $records->links() }}
        </div>
    </div>
</div>
