<div>
    @if ($runId && $status)
        <div
            @if (in_array($status, [\App\Models\PayrollRun::QUEUED, \App\Models\PayrollRun::PROCESSING], true)) wire:poll.3s="poll" @endif
            class="mt-5 rounded-2xl border border-border bg-surface p-4 shadow-sm"
            aria-live="polite"
            aria-labelledby="payroll-run-{{ $runId }}-heading"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-strong">Procesamiento de nómina</p>
                    <h3 id="payroll-run-{{ $runId }}-heading" class="mt-1 font-semibold text-text">
                        {{ match ($status) {
                            \App\Models\PayrollRun::QUEUED => 'Nómina en cola',
                            \App\Models\PayrollRun::PROCESSING => 'Procesando nómina',
                            \App\Models\PayrollRun::COMPLETED => 'Nómina procesada',
                            \App\Models\PayrollRun::FAILED => 'No se pudo procesar la nómina.',
                        } }}
                    </h3>
                    <p class="mt-1 text-sm text-text-muted">Referencia #{{ $runId }}</p>
                </div>
                @if (in_array($status, [\App\Models\PayrollRun::QUEUED, \App\Models\PayrollRun::PROCESSING], true))
                    <div class="flex items-center gap-2 text-sm font-medium text-brand-strong" role="status">
                        <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>
                        Seguimos comprobando el estado automáticamente.
                    </div>
                @endif
            </div>

            @if ($delayed)
                <x-ui.alert class="mt-4" variant="warning">
                    La cola está demorada. Verificá que el worker esté activo; esta pantalla seguirá comprobando el estado automáticamente.
                </x-ui.alert>
            @endif

            @if ($recoverable)
                <x-ui.alert class="mt-4" variant="warning">La ejecución perdió su lease. Recuperala explícitamente antes de reintentar.</x-ui.alert>
                <x-ui.loading-button type="button" wire:click="recover" target="recover" loading-label="Recuperando…" class="mt-3 rounded-lg bg-brand px-3 py-2 text-xs font-bold text-white">Recuperar ejecución</x-ui.loading-button>
            @endif

            @if ($status === \App\Models\PayrollRun::FAILED)
                <x-ui.alert class="mt-4" variant="danger">
                    {{ $failureCode === 'attendance_review_blocked'
                        ? 'La revisión de asistencia tiene bloqueadores pendientes.'
                        : 'La ejecución no pudo completarse.' }}
                </x-ui.alert>
                <x-ui.loading-button
                    type="button"
                    wire:click="retry"
                    target="retry"
                    loading-label="Reintentando…"
                    class="mt-3 rounded-lg bg-brand px-3 py-2 text-xs font-bold text-white"
                >
                    Intentar nuevamente
                </x-ui.loading-button>
            @endif
        </div>
    @endif
</div>
