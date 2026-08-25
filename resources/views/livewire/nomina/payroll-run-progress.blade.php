<div>
    @if ($runId && $status)
        <div
            @if (in_array($status, [\App\Models\PayrollRun::QUEUED, \App\Models\PayrollRun::PROCESSING], true)) wire:poll.3s="poll" @endif
            aria-live="polite"
            class="mt-5 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-950"
        >
            <p class="font-bold">
                {{ match ($status) {
                    \App\Models\PayrollRun::QUEUED => 'Nómina en cola',
                    \App\Models\PayrollRun::PROCESSING => 'Procesando nómina',
                    \App\Models\PayrollRun::COMPLETED => 'Nómina procesada',
                    \App\Models\PayrollRun::FAILED => 'No se pudo procesar la nómina.',
                } }}
            </p>
            <p class="mt-1 text-xs text-indigo-700">Referencia #{{ $runId }}</p>
            @if ($delayed)
                <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900" role="status">
                    La cola está demorada. Verificá que el worker esté activo; esta pantalla seguirá comprobando el estado automáticamente.
                </p>
            @endif
            @if ($recoverable)
                <p class="mt-2 text-xs text-indigo-700" role="status">La ejecución perdió su lease. Recuperala explícitamente antes de reintentar.</p>
                <x-ui.loading-button type="button" wire:click="recover" target="recover" loading-label="Recuperando…" class="mt-3 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700">Recuperar ejecución</x-ui.loading-button>
            @endif
            @if ($status === \App\Models\PayrollRun::FAILED)
                <p class="mt-2 text-xs text-indigo-700" role="status">
                    {{ $failureCode === 'attendance_review_blocked'
                        ? 'La revisión de asistencia tiene bloqueadores pendientes.'
                        : 'La ejecución no pudo completarse.' }}
                </p>
                <x-ui.loading-button
                    type="button"
                    wire:click="retry"
                    target="retry"
                    loading-label="Reintentando…"
                    class="mt-3 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700"
                >
                    Intentar nuevamente
                </x-ui.loading-button>
            @endif
        </div>
    @endif
</div>
