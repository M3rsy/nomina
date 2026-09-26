<div class="relative min-h-screen bg-surface-muted px-4 py-8 sm:px-6 lg:px-8" data-vacations-index="workspace">
    <x-ui.loading-overlay target="approve,adjustBalance,cancel" message="Actualizando vacaciones y saldo…" />
    <div class="mx-auto max-w-7xl space-y-6">
        <header data-vacation-section="hero" class="relative overflow-hidden rounded-3xl border border-border bg-surface shadow-sm">
            <div class="h-1.5 bg-gradient-to-r from-brand via-dashboard-info to-success" aria-hidden="true"></div>
            <div class="flex flex-col gap-6 p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-full bg-brand/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-brand">Gestión de ausencias</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] text-success"><span class="h-1.5 w-1.5 rounded-full bg-success"></span>Tiempo remunerado</span>
                    </div>
                    <h1 class="mt-4 text-3xl font-black tracking-tight text-text sm:text-4xl">Vacaciones pagadas</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-text-muted">Aprobá rangos, conservá la jornada pagable y administrá el saldo del equipo con historial completo.</p>
                </div>
                @can('vacations.manage')
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button wire:click="openAdjustmentModal" @disabled($companyId === null) class="min-h-11 rounded-2xl border border-border bg-surface px-4 text-sm font-bold text-text shadow-sm transition hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50">Ajustar saldo</button>
                        <button wire:click="openCreateModal" @disabled($companyId === null) class="min-h-11 rounded-2xl bg-brand px-4 text-sm font-bold text-white shadow-sm transition hover:bg-brand-strong disabled:cursor-not-allowed disabled:opacity-50">Aprobar vacaciones</button>
                    </div>
                @endcan
            </div>
        </header>

        @if ($companyId === null)
            <div class="rounded-3xl border border-warning/30 bg-warning/10 p-4 text-sm text-warning-strong">Seleccioná una empresa para consultar y gestionar sus vacaciones.</div>
        @else
            <section data-vacation-section="summary" aria-label="Resumen de vacaciones" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.14em] text-text-muted">Registros visibles</p><p class="mt-2 text-3xl font-black text-text">{{ $vacations->total() }}</p><p class="mt-2 text-xs text-text-muted">Solicitudes según los filtros.</p></article>
                <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.14em] text-text-muted">Estado consultado</p><p class="mt-2 text-xl font-black text-text">{{ match ($status) { 'approved' => 'Aprobadas', 'cancelled' => 'Canceladas', default => 'Todos' } }}</p><p class="mt-2 text-xs text-text-muted">Segmento actual del historial.</p></article>
                <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.14em] text-text-muted">Saldo gestionable</p><p class="mt-2 text-xl font-black text-text">{{ count($balances) }} empleados</p><p class="mt-2 text-xs text-text-muted">Personas con saldo en la empresa activa.</p></article>
            </section>

            <section data-vacation-section="filters" class="rounded-3xl border border-border bg-surface p-5 shadow-sm">
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
                    <label><span class="mb-1.5 block text-sm font-semibold text-text">Buscar empleado</span><input wire:model.live.debounce.300ms="search" type="search" placeholder="Nombre, apellido o código…" class="h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30"></label>
                    <label><span class="mb-1.5 block text-sm font-semibold text-text">Estado</span><select wire:model.live="status" class="h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30"><option value="all">Todos</option><option value="approved">Aprobadas</option><option value="cancelled">Canceladas</option></select></label>
                </div>
            </section>

            <section data-vacation-section="records" class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-surface-muted text-left text-xs font-bold uppercase tracking-[0.12em] text-text-muted"><tr><th class="px-4 py-3">Empleado</th><th class="px-4 py-3">Rango inclusivo</th><th class="px-4 py-3">Jornadas</th><th class="px-4 py-3">Saldo</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Detalle</th><th class="px-4 py-3">Acciones</th></tr></thead>
                        <tbody>
                            @forelse ($vacations as $vacation)
                                @php($balance = (int) ($balances[$vacation->employee_id] ?? 0))
                                <tr class="border-t border-border align-top transition hover:bg-surface-muted/60" wire:key="vacation-{{ $vacation->id }}">
                                    <td class="px-4 py-3.5"><p class="text-sm font-bold text-text">{{ $vacation->employee->full_name }}</p><p class="text-xs text-text-muted">Código {{ $vacation->employee->external_id }}</p></td>
                                    <td class="whitespace-nowrap px-4 py-3.5 text-sm text-text-muted">{{ $vacation->start_date->format('d/m/Y') }} — {{ $vacation->end_date->format('d/m/Y') }}</td>
                                    <td class="px-4 py-3.5 text-sm text-text-muted">{{ $vacation->days->count() }} día(s)<br><span class="text-xs text-slate-500">{{ $vacation->days->sum('planned_minutes') }} min pagables</span></td>
                                    <td class="px-4 py-3.5"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $balance < 0 ? 'bg-danger/10 text-danger' : 'bg-dashboard-info/10 text-dashboard-info-strong' }}">{{ $balance }} día(s)</span></td>
                                    <td class="px-4 py-3.5"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $vacation->status === 'approved' ? 'bg-success/10 text-success-strong' : 'bg-surface-muted text-text-muted' }}">{{ $vacation->status === 'approved' ? 'Aprobada' : 'Cancelada' }}</span></td>
                                    <td class="px-4 py-3.5 text-xs text-text-muted">
                                        @if ($vacation->notes)<p>{{ $vacation->notes }}</p>@endif
                                        @if (count($vacation->excluded_dates ?? []) > 0)
                                            <details class="mt-1"><summary class="cursor-pointer font-semibold text-amber-700">{{ count($vacation->excluded_dates) }} fecha(s) excluida(s)</summary><ul class="mt-1 space-y-1">@foreach ($vacation->excluded_dates as $excluded)<li>{{ $excluded['date'] }}: {{ $excluded['label'] }}</li>@endforeach</ul></details>
                                        @endif
                                        @if ($vacation->status === 'cancelled')<p class="mt-1 text-rose-700">{{ $vacation->cancellation_reason }}</p>@endif
                                    </td>
                                    <td class="px-4 py-3.5">@can('vacations.manage') @if ($vacation->status === 'approved')<button wire:click="confirmCancellation({{ $vacation->id }})" class="min-h-9 rounded-lg border border-rose-200 bg-rose-50 px-3 text-xs font-semibold text-rose-700">Cancelar</button>@else<span class="text-xs text-slate-400">Sin acciones</span>@endif @endcan</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No hay vacaciones registradas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
            <div>{{ $vacations->links() }}</div>
        @endif
    </div>

    @if ($showCreateModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="vacation-create-title" tabindex="-1" x-data x-ref="dialog" x-init="$nextTick(() => $refs.dialog.focus())" @keydown.escape.window="$wire.closeCreateModal()">
            <div data-vacation-modal="approval" class="relative my-auto w-full max-w-xl overflow-hidden rounded-3xl border border-border bg-surface shadow-2xl">
            <div class="flex items-start justify-between gap-4 border-b border-border px-6 py-5 sm:px-7"><div><div class="flex flex-wrap items-center gap-2"><h2 id="vacation-create-title" class="text-xl font-black tracking-tight text-text">Aprobar vacaciones pagadas</h2><span class="inline-flex rounded-full border border-success/20 bg-success/10 px-2 py-0.5 text-[11px] font-bold text-success">Nuevo registro</span></div><p class="mt-1.5 text-xs leading-5 text-text-muted">Solo se consumirán jornadas programadas; descansos semanales y feriados activos se excluirán del cálculo.</p></div><button type="button" wire:click="closeCreateModal" aria-label="Cerrar ventana" class="rounded-full p-1.5 text-text-muted transition hover:bg-surface-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"><span aria-hidden="true" class="text-xl leading-none">×</span></button></div>
            @error('vacation')<p class="mx-6 mt-4 rounded-2xl border border-danger/20 bg-danger/10 p-3 text-sm text-danger sm:mx-7">{{ $message }}</p>@enderror
            <div class="space-y-5 px-6 py-5 sm:px-7">
                <div data-vacation-modal-section="employee-picker" class="space-y-2"><label for="vacation-employee-search" class="block text-xs font-bold uppercase tracking-[0.12em] text-text-muted">Empleado destinatario</label><input id="vacation-employee-search" wire:model.live.debounce.300ms="vacationEmployeeSearch" type="search" placeholder="Buscar por nombre, código o clave…" class="h-11 w-full rounded-xl border border-border bg-surface-muted px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-brand/30"><label for="vacation-employee" class="sr-only">Seleccionar empleado</label><select id="vacation-employee" wire:model="employeeId" class="h-11 w-full rounded-xl border border-border bg-surface-muted px-3 text-sm font-medium text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-brand/30"><option value="">Seleccionar empleado…</option>@foreach($vacationEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — saldo {{ (int) ($balances[$employee->id] ?? 0) }} días</option>@endforeach</select>@if ($employeeId !== null)<p class="flex items-center justify-between px-1 text-xs text-text-muted"><span>Saldo disponible para el empleado seleccionado.</span><strong class="rounded-md border border-success/20 bg-success/10 px-2 py-0.5 text-success">{{ (int) ($balances[$employeeId] ?? 0) }} días</strong></p>@endif @error('employeeId')<p class="text-sm text-danger">{{ $message }}</p>@enderror @error('employee_id')<p class="text-sm text-danger">{{ $message }}</p>@enderror</div>
                <div data-vacation-modal-section="date-range" class="space-y-2"><p class="text-xs font-bold uppercase tracking-[0.12em] text-text-muted">Rango de descanso</p><div class="grid gap-3 sm:grid-cols-2"><label><span class="mb-1.5 block text-sm font-semibold text-text">Desde</span><input wire:model="startDate" type="date" class="h-11 w-full rounded-xl border border-border bg-surface-muted px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-brand/30">@error('startDate')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror @error('start_date')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</label><label><span class="mb-1.5 block text-sm font-semibold text-text">Hasta</span><input wire:model="endDate" type="date" class="h-11 w-full rounded-xl border border-border bg-surface-muted px-3 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-brand/30">@error('endDate')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror @error('end_date')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</label></div><p class="rounded-2xl border border-dashboard-info/20 bg-dashboard-info/10 px-3.5 py-3 text-xs leading-5 text-dashboard-info-strong">El cálculo de jornadas pagables, descansos y feriados se realiza en el servidor al aprobar para conservar el historial auditable.</p></div>
                <label class="block" for="vacation-notes"><span class="mb-1.5 block text-sm font-semibold text-text">Observaciones o motivo</span><textarea id="vacation-notes" wire:model="notes" rows="3" placeholder="Ingresá los detalles del acuerdo o justificación de las fechas otorgadas…" class="w-full rounded-xl border border-border bg-surface-muted px-3 py-2 text-sm text-text shadow-sm outline-none transition placeholder:text-text-muted focus-visible:border-brand focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-brand/30"></textarea>@error('notes')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</label>
            </div>
            </div><div class="flex flex-col-reverse gap-2 border-t border-border px-6 py-4 sm:flex-row sm:justify-end sm:px-7"><button type="button" wire:click="closeCreateModal" class="min-h-11 rounded-xl border border-border bg-surface px-4 text-sm font-semibold text-text transition hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand">Cerrar</button><x-ui.loading-button wire:click="approve" target="approve" loading-label="Aprobando…" class="min-h-11 rounded-xl bg-brand px-5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">Aprobar vacaciones</x-ui.loading-button></div>
            </div>
        </div>
    @endif

    @if ($showAdjustmentModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="vacation-adjustment-title" tabindex="-1" x-data x-ref="dialog" x-init="$nextTick(() => $refs.dialog.focus())" @keydown.escape.window="$wire.closeAdjustmentModal()"><div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <h2 id="vacation-adjustment-title" class="text-lg font-bold text-slate-900">Ajustar saldo</h2><p class="mt-1 text-sm text-slate-600">Positivo acredita, negativo descuenta. El saldo puede quedar negativo.</p>
            <div class="mt-4 space-y-4">
                <label class="block"><span class="text-sm font-medium text-slate-700">Buscar empleado</span><input wire:model.live.debounce.300ms="adjustmentEmployeeSearch" type="search" placeholder="Nombre, apellido, código o clave…" class="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                <label class="block"><span class="text-sm font-medium text-slate-700">Empleado</span><select wire:model="employeeId" class="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Seleccionar…</option>@foreach($adjustmentEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — saldo {{ (int) ($balances[$employee->id] ?? 0) }}</option>@endforeach</select>@error('employeeId')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label>
                <label class="block"><span class="text-sm font-medium text-slate-700">Días</span><input wire:model="adjustmentDays" type="number" class="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3">@error('adjustmentDays')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label>
                <label class="block"><span class="text-sm font-medium text-slate-700">Motivo</span><textarea wire:model="adjustmentReason" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>@error('adjustmentReason')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label>
            </div>
            <div class="mt-6 flex justify-end gap-2"><button wire:click="closeAdjustmentModal" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cerrar</button><x-ui.loading-button wire:click="adjustBalance" target="adjustBalance" loading-label="Guardando…" class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white">Guardar ajuste</x-ui.loading-button></div>
        </div></div>
    @endif

    @if ($showCancelModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="vacation-cancel-title" tabindex="-1" x-data x-ref="dialog" x-init="$nextTick(() => $refs.dialog.focus())" @keydown.escape.window="$wire.set('showCancelModal', false)"><div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <h2 id="vacation-cancel-title" class="text-lg font-bold text-slate-900">Cancelar vacaciones</h2><p class="mt-1 text-sm text-slate-600">Las jornadas dejarán de estar activas y el consumo se revertirá sin borrar el historial.</p>
            @error('vacation')<p class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ $message }}</p>@enderror
            <label class="mt-4 block"><span class="text-sm font-medium text-slate-700">Motivo obligatorio</span><textarea wire:model="cancellationReason" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>@error('cancellationReason')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror @error('cancellation_reason')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label>
            <div class="mt-6 flex justify-end gap-2"><button wire:click="$set('showCancelModal', false)" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cerrar</button><x-ui.loading-button wire:click="cancel" target="cancel" loading-label="Cancelando…" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white">Confirmar cancelación</x-ui.loading-button></div>
        </div></div>
    @endif
</div>
