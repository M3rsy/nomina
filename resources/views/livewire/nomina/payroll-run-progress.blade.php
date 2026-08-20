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
            @if ($status === \App\Models\PayrollRun::FAILED)
                <button
                    type="button"
                    wire:click="$dispatch('payroll-run-retry', { runId: {{ $runId }} })"
                    class="mt-3 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700"
                >
                    Intentar nuevamente
                </button>
            @endif
        </div>
    @endif
</div>
