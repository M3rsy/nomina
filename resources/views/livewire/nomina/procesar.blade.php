<div class="relative isolate mx-auto max-w-7xl px-4 py-8">
    <x-ui.loading-overlay target="approve" message="Validando y aprobando la nómina…" />

    <x-ui.page-header
        title="Revisión y finalización de nómina"
        description="Revisá los resultados congelados, confirmá riesgos y continuá solo con las acciones disponibles."
    >
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                <x-ui.button :href="route('nomina.revisar', ['payPeriod' => $payPeriod])" variant="secondary" wire:navigate>
                    Volver
                </x-ui.button>
                @if ($isCancelled)
                    <x-ui.badge variant="danger">Nómina cancelada</x-ui.badge>
                @else
                    @if ($canApprove)
                        <x-ui.button id="approve-payroll-trigger" wire:click="requestApprovalConfirmation">
                            Aprobar nómina
                        </x-ui.button>
                    @endif
                    @if ($canExport)
                        <x-ui.button :href="route('nomina.excel', ['payPeriod' => $payPeriod])" variant="secondary">
                            Generar Excel
                        </x-ui.button>
                    @endif
                @endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mt-6" aria-labelledby="payroll-finalization-status">
        <x-slot:header>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="payroll-finalization-status" class="font-semibold text-text">Estado del período</h2>
                    <p class="mt-1 text-sm text-text-muted">{{ $payPeriod->name ?? $payPeriod->slug }} · {{ $payPeriod->start_date->format('d/m/Y') }} – {{ $payPeriod->end_date->format('d/m/Y') }}</p>
                </div>
                <x-ui.badge :variant="$statusPresentation->badgeVariant">{{ $statusPresentation->label }}</x-ui.badge>
            </div>
        </x-slot:header>
        <p class="mb-4 text-sm text-text-muted">{{ $statusPresentation->copy }}</p>
        <x-nomina.payroll-workflow :phases="$phases" :presentation="$statusPresentation" />
    </x-ui.card>

    @if (session('success'))
        <x-ui.alert class="mt-6" variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if (session('warning'))
        <x-ui.alert class="mt-6" variant="warning">{{ session('warning') }}</x-ui.alert>
    @endif

    @if ($isCancelled)
        <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded">
            Nómina cancelada, no editable.
        </div>
    @endif

    @if ($locked && ! $isCancelled)
        <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded">
            Esta nómina está aprobada/exportada. Los registros no pueden modificarse directamente.
        </div>
    @endif

    <div class="mb-6 grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Empleados</div>
            <div class="text-xl font-bold">{{ $summary['total_employees'] }}</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Días</div>
            <div class="text-xl font-bold">{{ $summary['total_days'] }}</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Horas ordinarias</div>
            <div class="text-xl font-bold">{{ number_format($summary['ordinary_minutes'] / 60, 2) }}</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Horas extras</div>
            <div class="text-xl font-bold">
                {{ number_format(($summary['extra_25_minutes'] + $summary['extra_50_minutes'] + $summary['extra_75_minutes'] + $summary['extra_100_minutes']) / 60, 2) }}
            </div>
        </div>
    </div>

    <div class="mb-4 flex gap-4">
        <label class="block text-sm font-medium text-gray-700">Empleado
            <input wire:model.live="employee_id" type="number" min="1" class="mt-1 block rounded border-gray-300 shadow-sm" />
        </label>
        <label class="block text-sm font-medium text-gray-700">Estado
            <select wire:model.live="absence" class="mt-1 block rounded border-gray-300 shadow-sm">
                <option value="">Todos</option><option value="worked">Con jornada</option><option value="absence">Ausencias</option>
            </select>
        </label>
    </div>

    <div class="overflow-x-auto bg-white rounded shadow">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Nombre</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Entrada</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Salida</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cantidad Horas</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ordinarias</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ext 25%</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ext 50%</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ext 75%</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ext 100%</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($results as $result)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->employee_external_id }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->employee_name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->date->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->entry_at?->format('d/m/Y h:i A') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->exit_at?->format('d/m/Y h:i A') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->worked_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->ordinary_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->extra_25_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->extra_50_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->extra_75_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->extra_100_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            @if ($result->day_type === 'paid_vacation')
                                <span class="font-semibold text-sky-700">Vacación pagada</span>
                            @elseif ($result->is_absence)
                                @if ($result->is_justified)
                                    <span class="text-purple-600">Justificada</span>
                                @elseif ($result->unjustified)
                                    <span class="text-red-600">Ausencia</span>
                                @else
                                    <span class="text-orange-600">Falta marca</span>
                                @endif
                            @else
                                <span class="text-green-600">Normal</span>
                            @endif
                        </td>
                        <td class="px-4 py-2"><button wire:click="showEvidence({{ $result->id }})" class="text-indigo-700 underline">Evidencia</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($evidence)
        <section class="mt-4 rounded bg-white p-4 shadow"><h2 class="font-bold">Evidencia congelada</h2>
            <pre class="mt-2 overflow-auto text-xs">{{ json_encode($evidence->day_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>
    @endif

    @if ($results->isEmpty())
        <x-ui.empty-state class="mt-6" title="No hay resultados para mostrar">
            @if ($hasActiveFilters)
                No encontramos resultados con los filtros actuales. Limpiá los filtros para revisar toda la nómina congelada.
            @else
                Este período todavía no tiene filas congeladas para la generación actual.
            @endif
        </x-ui.empty-state>
    @endif

    <div class="mt-4">
        {{ $results->links() }}
    </div>

    @if ($showApprovalConfirmation)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-text/50 p-4"
            role="presentation"
            x-data
            x-init="$nextTick(() => $refs.cancelApproval.focus())"
            x-on:keydown.escape.window="$wire.cancelApprovalConfirmation()"
        >
            <section
                class="w-full max-w-lg rounded-3xl border border-border bg-surface p-6 shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="approval-confirmation-heading"
                aria-describedby="approval-confirmation-description"
                wire:loading.attr="aria-busy"
                wire:target="approve"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-warning-strong">Confirmación requerida</p>
                <h2 id="approval-confirmation-heading" class="mt-1 text-xl font-bold text-text">Confirmar aprobación</h2>
                <p id="approval-confirmation-description" class="mt-2 text-sm leading-6 text-text-muted">
                    Esta acción registra quién aprobó la nómina y habilita la exportación. La aprobación se revalida en el servidor antes de cambiar el estado.
                </p>
                <div class="mt-5 rounded-2xl border border-warning bg-warning-subtle p-4 text-sm text-warning-strong">
                    Verificá que los resultados congelados correspondan al período antes de confirmar.
                </div>
                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button
                        x-ref="cancelApproval"
                        variant="secondary"
                        wire:click="cancelApprovalConfirmation"
                        wire:loading.attr="disabled"
                        wire:target="approve"
                    >
                        Cancelar
                    </x-ui.button>
                    <x-ui.loading-button
                        type="button"
                        target="approve"
                        wire:click="approve"
                        loading-label="Aprobando…"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white"
                    >
                        Confirmar aprobación
                    </x-ui.loading-button>
                </div>
            </section>
        </div>
    @endif
</div>
