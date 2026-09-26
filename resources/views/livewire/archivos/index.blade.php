<div class="min-h-screen bg-surface-muted" data-files-index="workspace">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <nav aria-label="Miga de pan" class="text-sm font-semibold text-text-muted">
            Control de Asistencia / Relojes y Biometría
        </nav>

        <x-ui.page-header
            title="Archivos de marcas"
            description="Auditá las cargas de relojes y biometría, sus períodos y el estado de procesamiento desde un único lugar."
        >
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('nomina.index')">
                    Volver a períodos
                </x-ui.button>
                @can('create', App\Models\UploadedFile::class)
                    <x-ui.button :href="route('archivos.upload')" title="Subir archivo">
                        Cargar nuevo archivo
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        <section data-files-section="summary" aria-labelledby="files-summary-heading">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-strong">Resumen operativo</p>
                    <h2 id="files-summary-heading" class="mt-1 text-lg font-bold text-text">Estado de las cargas</h2>
                </div>
                <p class="text-sm text-text-muted">Datos del alcance y filtros actuales.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" aria-label="Resumen de estados">
                <x-ui.stat-card label="Total de archivos" :value="number_format(array_sum($statusCounts))" tone="brand">
                    Cargas registradas en la empresa.
                </x-ui.stat-card>

                @foreach ($statusOptions as $key => $label)
                    @if ($key === 'all')
                        @continue
                    @endif
                    @php
                        $tone = match ($key) {
                            'valid' => 'success',
                            'invalid', 'failed' => 'danger',
                            'processing' => 'warning',
                            default => 'neutral',
                        };
                    @endphp
                    <x-ui.stat-card :label="$label" :value="number_format($statusCounts[$key] ?? 0)" :tone="$tone">
                        Archivos con este estado.
                    </x-ui.stat-card>
                @endforeach
            </div>
        </section>

        <section
            data-files-section="filters"
            class="rounded-3xl border border-border bg-surface p-5 shadow-sm sm:p-6"
            aria-labelledby="files-filters-heading"
        >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-strong">Registro y control</p>
                    <h2 id="files-filters-heading" class="mt-1 text-xl font-bold text-text">Auditoría de cargas</h2>
                    <p class="mt-1 text-sm text-text-muted">Filtrá por archivo, estado, período o fecha de carga.</p>
                </div>
                <x-ui.button variant="secondary" wire:click="clearFilters">
                    Limpiar filtros
                </x-ui.button>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-6">
                <div class="lg:col-span-2">
                    <x-ui.input
                        id="files-search"
                        label="Buscar archivo"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Nombre de archivo o período"
                    />
                </div>

                <x-ui.select id="files-status" label="Estado" wire:model.live="status">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select id="files-pay-period" label="Período" wire:model.live="pay_period_id">
                    <option value="">Todos</option>
                    @foreach ($payPeriods as $payPeriod)
                        <option value="{{ $payPeriod->id }}">{{ $payPeriod->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input id="files-from-date" label="Desde" type="date" wire:model.live="from" />
                <x-ui.input id="files-to-date" label="Hasta" type="date" wire:model.live="to" />
            </div>

            <div class="mt-5 flex flex-wrap gap-2 text-xs" aria-label="Filtros activos">
                @if ($search !== '')
                    <span class="inline-flex items-center rounded-full border border-brand/30 bg-brand-subtle px-3 py-1.5 text-brand-strong">
                        Búsqueda: <span class="ml-1 font-semibold">{{ $search }}</span>
                    </span>
                @endif
                @if ($status !== 'all')
                    <span class="inline-flex items-center rounded-full border border-brand/30 bg-brand-subtle px-3 py-1.5 text-brand-strong">
                        Estado: <span class="ml-1 font-semibold">{{ $statusOptions[$status] ?? $status }}</span>
                    </span>
                @endif
                @if ($pay_period_id)
                    @php($selectedPayPeriod = $payPeriods->firstWhere('id', (int) $pay_period_id))
                    <span class="inline-flex items-center rounded-full border border-dashboard-info/30 bg-dashboard-info-subtle px-3 py-1.5 text-text">
                        Período: <span class="ml-1 font-semibold">{{ $selectedPayPeriod->name ?? $pay_period_id }}</span>
                    </span>
                @endif
                @if ($from !== '')
                    <span class="inline-flex items-center rounded-full border border-success/30 bg-success-subtle px-3 py-1.5 text-success-strong">
                        Desde: <span class="ml-1 font-semibold">{{ $from }}</span>
                    </span>
                @endif
                @if ($to !== '')
                    <span class="inline-flex items-center rounded-full border border-warning/40 bg-warning-subtle px-3 py-1.5 text-warning-strong">
                        Hasta: <span class="ml-1 font-semibold">{{ $to }}</span>
                    </span>
                @endif
                @if ($search === '' && $status === 'all' && empty($pay_period_id) && $from === '' && $to === '')
                    <span class="inline-flex items-center rounded-full border border-border bg-surface-muted px-3 py-1.5 text-text-muted">
                        Sin filtros activos
                    </span>
                @endif
            </div>
        </section>

        <section
            data-files-section="listing"
            class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm"
            aria-labelledby="files-listing-heading"
        >
            <div class="flex flex-col gap-1 border-b border-border px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-7">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-strong">Historial de importación</p>
                    <h2 id="files-listing-heading" class="mt-1 text-xl font-bold text-text">Archivos cargados</h2>
                </div>
                <p class="text-sm text-text-muted">{{ number_format($files->total()) }} resultados</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-surface-muted text-left text-xs font-semibold uppercase tracking-wide text-text-muted">
                        <tr>
                            <th class="px-5 py-3.5 sm:pl-7">Archivo</th>
                            <th class="px-4 py-3.5">Período</th>
                            <th class="px-4 py-3.5">Estado</th>
                            <th class="px-4 py-3.5 text-right">Registros</th>
                            <th class="px-4 py-3.5">Subida</th>
                            <th class="px-5 py-3.5 sm:pr-7">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($files as $file)
                            <tr class="transition hover:bg-surface-muted/70">
                                <td class="px-5 py-4 sm:pl-7">
                                    <div class="flex min-w-64 items-center gap-3">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-subtle text-brand-strong" aria-hidden="true">
                                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
                                                <path d="M14 2v6h6M8 13h8M8 17h5" />
                                            </svg>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="max-w-[24rem] truncate font-semibold text-text" title="{{ $file->original_name }}">{{ $file->original_name }}</p>
                                            <p class="mt-1 max-w-[24rem] truncate text-xs text-text-muted" title="{{ $file->stored_name }}">{{ $file->stored_name }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-sm text-text">{{ $file->payPeriod?->name ?? 'Sin período' }}</td>
                                <td class="px-4 py-4 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $this->statusClasses($file->status) }}">
                                        {{ $this->statusLabel($file->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-right text-sm font-semibold tabular-nums text-text">{{ number_format($file->raw_marks_count) }}</td>
                                <td class="px-4 py-4 text-sm text-text-muted">
                                    <time datetime="{{ $file->created_at->toIso8601String() }}">{{ $file->created_at->format('d/m/Y H:i') }}</time>
                                </td>
                                <td class="px-5 py-4 sm:pr-7">
                                    <div class="flex min-w-max flex-wrap gap-2">
                                        <a
                                            href="{{ route('archivos.show', $file) }}"
                                            class="inline-flex min-h-9 items-center rounded-lg bg-brand-subtle px-3 py-1 text-xs font-semibold text-brand-strong transition hover:bg-brand/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                                        >
                                            Ver detalle
                                        </a>
                                        @can('manage', $file)
                                            <a
                                                href="{{ route('archivos.upload', ['pay_period_id' => $file->pay_period_id]) }}"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-border bg-surface px-3 py-1 text-xs font-semibold text-text transition hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                                            >
                                                Reemplazar
                                            </a>
                                        @endcan
                                        @can('delete', $file)
                                            <button
                                                type="button"
                                                wire:click="openDeleteConfirmation({{ $file->id }})"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-danger/30 bg-danger-subtle px-3 py-1 text-xs font-semibold text-danger-strong transition hover:bg-danger/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                                            >
                                                Eliminar
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 sm:px-7">
                                    <x-ui.empty-state title="No se encontraron archivos">
                                        No hay cargas que coincidan con los filtros aplicados. Probá limpiar los filtros o cargá un nuevo archivo.
                                        @can('create', App\Models\UploadedFile::class)
                                            <x-slot:actions>
                                                <x-ui.button :href="route('archivos.upload')">Cargar nuevo archivo</x-ui.button>
                                            </x-slot:actions>
                                        @endcan
                                    </x-ui.empty-state>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pt-1">
            {{ $files->links() }}
        </div>

        @if ($deletingFileId)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-text/50 p-4" role="presentation">
                <div class="w-full max-w-lg rounded-3xl border border-border bg-surface p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="delete-file-heading">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-danger-strong">Acción irreversible</p>
                    <h2 id="delete-file-heading" class="mt-1 text-xl font-bold text-text">Eliminar archivo</h2>
                    <p class="mt-2 text-sm leading-6 text-text-muted">El archivo se ocultará de la lista y la eliminación quedará registrada en auditoría.</p>
                    <form wire:submit="deleteFile" class="mt-5 space-y-4">
                        <div>
                            <label for="file-deletion-reason" class="block text-sm font-semibold text-text">Motivo de eliminación</label>
                            <textarea id="file-deletion-reason" wire:model="deletionReason" rows="4" maxlength="500" required class="mt-2 block w-full rounded-xl border border-border bg-surface px-3 py-2 text-text shadow-sm outline-none focus:border-danger focus:ring-2 focus:ring-danger/30" placeholder="Explicá por qué se elimina este archivo"></textarea>
                            @error('deletionReason') <p class="mt-1 text-sm text-danger-strong">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="secondary" wire:click="closeDeleteConfirmation">Cancelar</x-ui.button>
                            <x-ui.loading-button type="submit" target="deleteFile" loading-label="Eliminando..." class="inline-flex min-h-11 items-center rounded-xl bg-danger px-4 py-2.5 text-sm font-semibold text-white">Eliminar archivo</x-ui.loading-button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
