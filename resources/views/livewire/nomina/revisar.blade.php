<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @if (session('success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
            {{ session('success') }}
        </div>
    @endif

    @php
        $summaryTotal = (int) ($summary['total'] ?? 0);
        $summaryValid = (int) ($summary['valid'] ?? 0);
        $summaryDuplicate = (int) ($summary['duplicate'] ?? 0);
        $summaryOutOfPeriod = (int) ($summary['out_of_period'] ?? 0);
        $summaryUnknown = (int) ($summary['unknown_employee'] ?? 0);
        $summaryCorrected = (int) ($summary['corrected'] ?? 0);
        $summaryDeleted = (int) ($summary['deleted'] ?? 0);
        $summaryJustified = (int) ($summary['justified'] ?? 0);

        $statusOptions = [
            '' => 'Todos',
            'pending' => 'Pendientes',
            'valid' => 'Válidos',
            'duplicate' => 'Duplicados',
            'out_of_period' => 'Fuera de período',
            'unknown_employee' => 'Empleados desconocidos',
            'corrected' => 'Corregidos',
            'deleted' => 'Eliminados',
            'justified' => 'Justificados',
        ];
        $selectedStatusLabel = $statusOptions[$status] ?? 'Todos';
        $selectedUploadedLabel = $uploadedFiles->firstWhere('id', (int) $uploaded_file_id)?->original_name ?? 'Todos los archivos';
    @endphp

    <header class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">Nómina &gt; Revisión</p>
                <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Revisar nómina</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">
                    Período {{ $payPeriod->slug ?? $payPeriod->id }} — {{ $payPeriod->start_date?->format('d/m/Y') }} a {{ $payPeriod->end_date?->format('d/m/Y') }}.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a
                    href="{{ route('nomina.index') }}"
                    class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Volver a períodos
                </a>

                @if ($isBlocked)
                    <button
                        type="button"
                        disabled
                        class="inline-flex min-h-11 cursor-not-allowed items-center rounded-xl bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-500"
                    >
                        Guardar borrador
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="saveDraft"
                        class="inline-flex min-h-11 items-center rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2"
                    >
                        Guardar borrador
                    </button>

                    <button
                        type="button"
                        wire:click="continueToReady"
                        class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Marcar lista para procesar
                    </button>
                @endif
            </div>
        </div>

        @if ($isBlocked)
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Este período está bloqueado (estado: {{ $payPeriod->status }}) y no permite modificaciones.
            </div>
        @endif
    </header>

    <section class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($summaryTotal) }}</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Válidos</p>
            <p class="mt-2 text-3xl font-black text-emerald-700">{{ number_format($summaryValid) }}</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Desconocidos</p>
            <p class="mt-2 text-3xl font-black text-rose-700">{{ number_format($summaryUnknown) }}</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Justificados</p>
            <p class="mt-2 text-3xl font-black text-violet-700">{{ number_format($summaryJustified) }}</p>
        </article>
    </section>

    <section class="mt-6 grid gap-4 lg:grid-cols-3">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-600">Registros no válidos</h2>
            <ul class="mt-3 space-y-2 text-sm text-slate-700">
                <li class="flex items-center justify-between"><span>Duplicados</span><strong>{{ $summaryDuplicate }}</strong></li>
                <li class="flex items-center justify-between"><span>Fuera de período</span><strong>{{ $summaryOutOfPeriod }}</strong></li>
                <li class="flex items-center justify-between"><span>Corregidos</span><strong>{{ $summaryCorrected }}</strong></li>
                <li class="flex items-center justify-between"><span>Eliminados</span><strong>{{ $summaryDeleted }}</strong></li>
            </ul>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-600">Filtros activos</h2>
            <p class="mt-2 text-sm text-slate-700">Estado: <span class="font-semibold">{{ $selectedStatusLabel }}</span></p>
            <p class="mt-1 text-sm text-slate-700">Archivo: <span class="font-semibold">{{ $selectedUploadedLabel }}</span></p>
            <p class="mt-1 text-sm text-slate-700">Búsqueda: <span class="font-semibold">{{ $search !== '' ? $search : 'Sin búsqueda' }}</span></p>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-600">Faltas detectadas</h2>
            <p class="mt-2 text-2xl font-black text-slate-900">{{ $faltas->count() }}</p>
            <p class="text-sm text-slate-600">Con base en calendario y marca de asistencia por período.</p>
        </article>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-4 flex flex-wrap gap-3">
            <label class="flex-1 min-w-44" for="search">
                <span class="mb-1 block text-xs font-medium text-slate-700">Buscar por empleado/código/observación</span>
                <input
                    id="search"
                    type="text"
                    wire:model.live.debounce.250ms="search"
                    placeholder="p. ej. 1001 o apellido"
                    class="h-11 w-full rounded-xl border border-slate-300 px-3"
                >
            </label>

            <label class="flex-1 min-w-44" for="status">
                <span class="mb-1 block text-xs font-medium text-slate-700">Estado</span>
                <select id="status" wire:model.live="status" class="h-11 w-full rounded-xl border border-slate-300 px-3">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex-1 min-w-44" for="uploaded_file_id">
                <span class="mb-1 block text-xs font-medium text-slate-700">Archivo de origen</span>
                <select id="uploaded_file_id" wire:model.live="uploaded_file_id" class="h-11 w-full rounded-xl border border-slate-300 px-3">
                    <option value="">Todos los archivos</option>
                    @foreach ($uploadedFiles as $uploadedFile)
                        <option value="{{ $uploadedFile->id }}">{{ $uploadedFile->original_name }}</option>
                    @endforeach
                </select>
            </label>

            <div class="flex items-end">
                <button
                    type="button"
                    wire:click="$set('search', ''); $set('status', ''); $set('uploaded_file_id', null);"
                    class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Limpiar
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Fila</th>
                        <th class="px-4 py-3">Código</th>
                        <th class="px-4 py-3">Empleado</th>
                        <th class="px-4 py-3">Fecha/hora</th>
                        <th class="px-4 py-3">Archivo</th>
                        <th class="px-4 py-3">Estado</th>
                        <th class="px-4 py-3">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse ($records as $record)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900">#{{ $record->row_number }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $record->employee_external_id }}</td>
                            <td class="px-4 py-3 text-slate-900">{{ $record->employee?->full_name ?? 'Sin empleado' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ optional($record->event_at)?->format('d/m/Y H:i') ?? '-' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $record->uploadedFile?->original_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-700">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $this->statusClass($record->status) }}">
                                    {{ $this->statusLabel($record->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="openEditRawMark({{ $record->id }})"
                                        @disabled($isBlocked)
                                        class="rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Editar
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="openAssignModal({{ $record->id }})"
                                        @disabled($isBlocked)
                                        class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Asignar
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="markCorrected({{ $record->id }})"
                                        @disabled($isBlocked)
                                        class="rounded-md border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Corregir
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="openDeleteRawMark({{ $record->id }})"
                                        @disabled($isBlocked)
                                        class="rounded-md border border-rose-300 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Eliminar
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No hay registros para mostrar con estos filtros.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pt-2">{{ $records->links() }}</div>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-600">Faltas detectadas</h2>
        <div class="mt-4 space-y-2">
            @forelse ($faltas as $falta)
                <article class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-sm font-semibold text-slate-900">{{ $falta['employee']->full_name }}</p>
                    <p class="text-sm text-slate-600">
                        {{ $falta['date']->format('d/m/Y') }}
                        @if ($falta['justified_absence'])
                            — Justificada ({{ $falta['justified_absence']->reason }})
                        @else
                            — Sin justificar
                        @endif
                    </p>
                </article>
            @empty
                <p class="text-sm text-slate-600">No se detectaron ausencias no justificadas para el período.</p>
            @endforelse
        </div>
    </section>

    @if ($showEditModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                <h2 class="text-lg font-bold">Editar fecha/hora de marca</h2>
                <form wire:submit.prevent="saveEditRawMark" class="mt-4 space-y-4">
                    <label for="edit_event_at" class="block text-sm">
                        <span class="font-semibold">Nueva fecha/hora</span>
                        <input
                            id="edit_event_at"
                            type="text"
                            wire:model="editEventAt"
                            class="mt-2 h-11 w-full rounded-xl border border-slate-300 px-3"
                            placeholder="YYYY-mm-dd HH:ii:ss"
                        >
                    </label>

                    @if ($editWarning)
                        <p class="rounded-lg bg-amber-50 p-2 text-sm text-amber-900">{{ $editWarning }}</p>
                    @endif

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="closeEditModal" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cancelar</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                <h2 class="text-lg font-bold">Eliminar marca</h2>
                <p class="mt-2 text-sm text-slate-600">La marca se marcará como eliminada y se conserva su rastro en historial.</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="closeDeleteModal" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cancelar</button>
                    <button type="button" wire:click="deleteRawMark" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white">Eliminar</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showAssignModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-xl rounded-2xl bg-white p-5 shadow-xl">
                <h2 class="text-lg font-bold">Asignar empleado</h2>
                <form wire:submit.prevent="saveAssign" class="mt-4 space-y-4">
                    <label for="assign_employee" class="block text-sm">
                        <span class="font-semibold">Empleado</span>
                        <select id="assign_employee" wire:model="assignEmployeeId" class="mt-2 h-11 w-full rounded-xl border border-slate-300 px-3">
                            <option value="">Seleccionar empleado</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->full_name }} ({{ $employee->external_id }})</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="assignApplyAll" class="size-4">
                        <span>Aplicar a todas las marcas sin empleado de este mismo externo</span>
                    </label>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="closeAssignModal" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cancelar</button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showReadyConfirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
                <h2 class="text-lg font-bold">Confirmar avance</h2>
                <p class="mt-2 text-sm text-slate-700">{{ $readyMessage ?? '¿Seguro que desea continuar con el estado listo para procesar?' }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="cancelReadyConfirm" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cancelar</button>
                    <button type="button" wire:click="confirmContinueToReady" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Confirmar</button>
                </div>
            </div>
        </div>
    @endif
</div>
