<div class="min-h-screen bg-surface-muted px-4 py-8 sm:px-6 lg:px-8" data-audit-index="workspace">
    @php
        $totalEntries = $entries->total();
        $visibleEntries = $entries->count();
        $hasFilters = $type !== 'all' || filled($user) || filled($from) || filled($to);
    @endphp
    <div class="mx-auto max-w-7xl space-y-6">
        <header data-audit-section="hero" class="relative overflow-hidden rounded-3xl border border-border bg-surface shadow-sm">
            <div class="h-1.5 bg-gradient-to-r from-brand via-dashboard-info to-success" aria-hidden="true"></div>
            <div class="flex flex-col gap-6 p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-brand/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-brand">Seguridad y trazabilidad</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] text-success"><span class="h-1.5 w-1.5 rounded-full bg-success"></span>Registro de solo lectura</span>
                    </div>
                    <h1 class="mt-4 text-3xl font-black tracking-tight text-text sm:text-4xl">Auditoría del sistema</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-text-muted">Consultá eventos históricos con trazabilidad de empresa, usuario, fecha y descripción técnica.</p>
                </div>
                <div class="flex items-center gap-3 rounded-2xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-brand/10 text-brand" aria-hidden="true">↯</span>
                    <span><strong class="block text-text">Integridad validada</strong><span class="text-xs">Feed protegido por permisos</span></span>
                </div>
            </div>
        </header>

        <section data-audit-section="summary" aria-label="Resumen de auditoría" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.14em] text-text-muted">Eventos encontrados</p><p class="mt-2 text-3xl font-black text-text">{{ number_format($totalEntries) }}</p><p class="mt-2 text-xs text-text-muted">Total para el contexto consultado.</p></article>
            <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.14em] text-text-muted">En esta página</p><p class="mt-2 text-3xl font-black text-text">{{ $visibleEntries }}</p><p class="mt-2 text-xs text-text-muted">Registros listos para inspección.</p></article>
            <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.14em] text-text-muted">Consulta actual</p><p class="mt-2 text-xl font-black text-text">{{ $hasFilters ? 'Filtrada' : 'Historial completo' }}</p><p class="mt-2 text-xs text-text-muted">{{ $hasFilters ? 'Se aplicaron filtros al feed.' : 'Sin filtros activos.' }}</p></article>
        </section>

        <section data-audit-section="filters" class="rounded-3xl border border-border bg-surface p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-start gap-4">
                <label for="type" class="min-w-52">
                    <span class="mb-1.5 block text-sm font-semibold text-text">Tipo de evento</span>
                    <select id="type" wire:model.live="type" class="mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label for="user" class="min-w-60">
                    <span class="mb-1.5 block text-sm font-semibold text-text">Usuario (correo)</span>
                    <input id="user" type="text" wire:model.live.debounce.300ms="user" placeholder="correo@ejemplo.com" class="mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30">
                </label>

                <label for="from" class="min-w-40">
                    <span class="mb-1.5 block text-sm font-semibold text-text">Desde</span>
                    <input id="from" type="date" wire:model.live="from" class="mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30">
                </label>

                <label for="to" class="min-w-40">
                    <span class="mb-1.5 block text-sm font-semibold text-text">Hasta</span>
                    <input id="to" type="date" wire:model.live="to" class="mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30">
                </label>
            </div>

            <div class="flex flex-wrap gap-2 text-xs">
                @if ($type !== 'all')
                    <span class="inline-flex items-center rounded-full border border-brand/20 bg-brand/10 px-3 py-1 text-brand">Tipo: <span class="ml-1 font-semibold">{{ $types[$type] ?? $type }}</span></span>
                @endif
                @if (! empty($user))
                    <span class="inline-flex items-center rounded-full border border-dashboard-info/20 bg-dashboard-info/10 px-3 py-1 text-dashboard-info-strong">Usuario: <span class="ml-1 font-semibold">{{ $user }}</span></span>
                @endif
                @if (! empty($from))
                    <span class="inline-flex items-center rounded-full border border-dashboard-info/20 bg-dashboard-info/10 px-3 py-1 text-dashboard-info-strong">Desde: <span class="ml-1 font-semibold">{{ $from }}</span></span>
                @endif
                @if (! empty($to))
                    <span class="inline-flex items-center rounded-full border border-success/20 bg-success/10 px-3 py-1 text-success">Hasta: <span class="ml-1 font-semibold">{{ $to }}</span></span>
                @endif
                @if ($type === 'all' && empty($user) && empty($from) && empty($to))
                    <span class="inline-flex items-center rounded-full border border-border bg-surface-muted px-3 py-1 text-text-muted">Sin filtros activos</span>
                @endif
            </div>

            @if (! $hasScheduleAssignmentTable && in_array($type, ['all', 'schedule_assignment'], true))
                <div class="mt-4 rounded-2xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning-strong">
                    No se está mostrando el tipo "Asignaciones de jornada" porque la tabla
                    <span class="font-semibold">employee_schedule_assignments</span> no existe en esta base.
                    Ejecutá la migración correspondiente para recuperar ese historial en auditoría.
                </div>
            @endif
        </section>

        <section data-audit-section="records" class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4 sm:px-6">
                <div><h2 class="text-sm font-black text-text">Bitácora de eventos auditados</h2><p class="mt-1 text-xs text-text-muted">{{ number_format($totalEntries) }} registros disponibles para este contexto.</p></div>
                <span class="inline-flex items-center gap-2 rounded-full bg-success/10 px-3 py-1.5 text-xs font-semibold text-success"><span class="h-1.5 w-1.5 rounded-full bg-success"></span>Integridad validada</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-surface-muted text-left text-xs font-bold uppercase tracking-[0.12em] text-text-muted">
                        <tr>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Empresa</th>
                            <th class="px-4 py-3">Usuario</th>
                            <th class="px-4 py-3">Descripción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            <tr class="border-t border-border transition hover:bg-surface-muted/60">
                                <td class="whitespace-nowrap px-4 py-3.5 font-mono text-xs font-semibold text-text">{{ $entry->createdAt->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3.5 text-sm font-semibold text-text-muted">{{ $entry->typeLabel }}</td>
                                <td class="px-4 py-3.5 text-sm text-text-muted">{{ $entry->companyName ?? 'N/A' }}</td>
                                <td class="px-4 py-3.5 text-sm font-mono text-text-muted">{{ $entry->userEmail ?? 'N/A' }}</td>
                                <td class="max-w-xl px-4 py-3.5 text-sm text-text-muted">{{ $entry->description }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-text-muted">No hay eventos de auditoría.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pt-1">
            {{ $entries->links() }}
        </div>
    </div>
</div>
