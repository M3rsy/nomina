<div class="relative min-h-screen bg-slate-50 px-4 py-8 sm:px-6 lg:px-8">
    <x-ui.loading-overlay target="approve,adjustBalance,cancel" message="Actualizando vacaciones y saldo…" />
    <div class="mx-auto max-w-7xl space-y-5">
        <header class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-emerald-700">Tiempo remunerado</p>
                    <h1 class="mt-3 text-3xl font-black text-slate-950">Vacaciones pagadas</h1>
                    <p class="mt-2 text-sm text-slate-600">Aprobá rangos sin marcas, conservá la jornada pagable y administrá el saldo con historial.</p>
                </div>
                @can('vacations.manage')
                    <div class="flex gap-2">
                        <button wire:click="openAdjustmentModal" @disabled($companyId === null) class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold disabled:opacity-50">Ajustar saldo</button>
                        <button wire:click="openCreateModal" @disabled($companyId === null) class="min-h-11 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white disabled:opacity-50">Aprobar vacaciones</button>
                    </div>
                @endcan
            </div>
        </header>

        @if ($companyId === null)
            <div class="rounded-3xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Seleccioná una empresa para consultar y gestionar sus vacaciones.</div>
        @else
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
                    <label><span class="mb-1 block text-xs font-medium text-slate-700">Buscar empleado</span><input wire:model.live.debounce.300ms="search" type="search" placeholder="Nombre, apellido o código…" class="h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label><span class="mb-1 block text-xs font-medium text-slate-700">Estado</span><select wire:model.live="status" class="h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="all">Todos</option><option value="approved">Aprobadas</option><option value="cancelled">Canceladas</option></select></label>
                </div>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600"><tr><th class="px-4 py-3">Empleado</th><th class="px-4 py-3">Rango inclusivo</th><th class="px-4 py-3">Jornadas</th><th class="px-4 py-3">Saldo</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Detalle</th><th class="px-4 py-3">Acciones</th></tr></thead>
                        <tbody>
                            @forelse ($vacations as $vacation)
                                @php($balance = (int) ($balances[$vacation->employee_id] ?? 0))
                                <tr class="border-t border-slate-200 align-top" wire:key="vacation-{{ $vacation->id }}">
                                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-slate-900">{{ $vacation->employee->full_name }}</p><p class="text-xs text-slate-500">Código {{ $vacation->employee->external_id }}</p></td>
                                    <td class="whitespace-nowrap px-4 py-3.5 text-sm text-slate-700">{{ $vacation->start_date->format('d/m/Y') }} — {{ $vacation->end_date->format('d/m/Y') }}</td>
                                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ $vacation->days->count() }} día(s)<br><span class="text-xs text-slate-500">{{ $vacation->days->sum('planned_minutes') }} min pagables</span></td>
                                    <td class="px-4 py-3.5"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $balance < 0 ? 'bg-rose-50 text-rose-700' : 'bg-sky-50 text-sky-700' }}">{{ $balance }} día(s)</span></td>
                                    <td class="px-4 py-3.5"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $vacation->status === 'approved' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $vacation->status === 'approved' ? 'Aprobada' : 'Cancelada' }}</span></td>
                                    <td class="px-4 py-3.5 text-xs text-slate-600">
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
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4"><div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
            <h2 class="text-lg font-bold text-slate-900">Aprobar vacaciones pagadas</h2><p class="mt-1 text-sm text-slate-600">Solo se consumirán jornadas programadas; descansos y feriados activos se excluirán.</p>
            @error('vacation')<p class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ $message }}</p>@enderror
            <div class="mt-4 space-y-4">
                <label class="block"><span class="text-sm font-medium text-slate-700">Buscar empleado</span><input wire:model.live.debounce.300ms="vacationEmployeeSearch" type="search" placeholder="Nombre, apellido, código o clave…" class="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                <label class="block"><span class="text-sm font-medium text-slate-700">Empleado</span><select wire:model="employeeId" class="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Seleccionar…</option>@foreach($vacationEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — saldo {{ (int) ($balances[$employee->id] ?? 0) }}</option>@endforeach</select>@error('employeeId')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror @error('employee_id')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label>
                <div class="grid gap-3 sm:grid-cols-2"><label><span class="text-sm font-medium text-slate-700">Desde</span><input wire:model="startDate" type="date" class="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3">@error('startDate')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror @error('start_date')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label><label><span class="text-sm font-medium text-slate-700">Hasta</span><input wire:model="endDate" type="date" class="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3">@error('endDate')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror @error('end_date')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label></div>
                <label class="block"><span class="text-sm font-medium text-slate-700">Observaciones</span><textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>@error('notes')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label>
            </div>
            <div class="mt-6 flex justify-end gap-2"><button wire:click="closeCreateModal" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cerrar</button><x-ui.loading-button wire:click="approve" target="approve" loading-label="Aprobando…" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Aprobar</x-ui.loading-button></div>
        </div></div>
    @endif

    @if ($showAdjustmentModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4"><div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <h2 class="text-lg font-bold text-slate-900">Ajustar saldo</h2><p class="mt-1 text-sm text-slate-600">Positivo acredita, negativo descuenta. El saldo puede quedar negativo.</p>
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
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4"><div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <h2 class="text-lg font-bold text-slate-900">Cancelar vacaciones</h2><p class="mt-1 text-sm text-slate-600">Las jornadas dejarán de estar activas y el consumo se revertirá sin borrar el historial.</p>
            @error('vacation')<p class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ $message }}</p>@enderror
            <label class="mt-4 block"><span class="text-sm font-medium text-slate-700">Motivo obligatorio</span><textarea wire:model="cancellationReason" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>@error('cancellationReason')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror @error('cancellation_reason')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror</label>
            <div class="mt-6 flex justify-end gap-2"><button wire:click="$set('showCancelModal', false)" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Cerrar</button><x-ui.loading-button wire:click="cancel" target="cancel" loading-label="Cancelando…" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white">Confirmar cancelación</x-ui.loading-button></div>
        </div></div>
    @endif
</div>
