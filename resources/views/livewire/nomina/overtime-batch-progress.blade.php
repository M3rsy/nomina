<div>
    @if ($batchId)
        <div
            @if (!($progress['terminal'] ?? true)) wire:poll.3s="poll" @endif
            aria-live="polite"
            class="mt-5 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-950"
        >
            <div class="flex items-center justify-between gap-3">
                <p class="font-bold">Lote #{{ $batchId }} · {{ $progress['status'] ?? 'cargando' }}</p>
                <p>{{ $progress['succeeded'] ?? 0 }} de {{ $progress['total'] ?? 0 }} procesados</p>
            </div>
            @if (($progress['failed'] ?? 0) > 0)
                <p class="mt-2 font-semibold text-rose-800">{{ $progress['failed'] }} candidatos no pudieron procesarse.</p>
                <ul class="mt-1 list-disc pl-5 text-rose-800">
                    @foreach ($batchErrors as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @elseif (($progress['status'] ?? null) === 'failed')
                <p class="mt-2 font-semibold text-rose-800">El lote no pudo completarse. Puede intentarlo nuevamente.</p>
            @elseif ($progress['terminal'] ?? false)
                <p class="mt-2 font-semibold text-emerald-800">El lote terminó correctamente.</p>
            @endif
        </div>
    @endif
</div>
