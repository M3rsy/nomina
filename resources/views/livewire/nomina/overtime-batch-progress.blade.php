<div>
    @if ($batchId)
        <div
            @if (!($progress['terminal'] ?? true)) wire:poll.3s="poll" @endif
            class="mt-5 rounded-2xl border border-border bg-surface p-4 shadow-sm"
            aria-live="polite"
            aria-labelledby="overtime-batch-{{ $batchId }}-heading"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-strong">Decisión masiva</p>
                    <h3 id="overtime-batch-{{ $batchId }}-heading" class="mt-1 font-semibold text-text">
                        Lote #{{ $batchId }} · {{ $progress['status'] ?? 'cargando' }}
                    </h3>
                    <p class="mt-1 text-sm text-text-muted">
                        {{ $progress['completed'] ?? 0 }} de {{ $progress['total'] ?? 0 }} completados; {{ $progress['succeeded'] ?? 0 }} exitosos, {{ $progress['failed'] ?? 0 }} fallidos, {{ $progress['processing'] ?? 0 }} en proceso y {{ $progress['pending'] ?? 0 }} pendientes.
                    </p>
                </div>
                @if (($progress['total'] ?? 0) > 0)
                    <div class="min-w-48 text-sm text-text-muted">
                        <progress class="h-3 w-full overflow-hidden rounded-full" value="{{ $progress['completed'] ?? 0 }}" max="{{ $progress['total'] }}">
                            {{ $progress['percentage'] }}%
                        </progress>
                        <p class="mt-1 text-right">{{ $progress['percentage'] }}% completado</p>
                    </div>
                @endif
            </div>

            @if (($progress['failed'] ?? 0) > 0)
                <x-ui.alert class="mt-4" variant="danger" title="{{ $progress['failed'] }} candidatos no pudieron procesarse">
                    <ul class="list-disc pl-5">
                        @foreach ($batchErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @elseif (($progress['status'] ?? null) === 'failed')
                <x-ui.alert class="mt-4" variant="danger">El lote no pudo completarse. Puede intentarlo nuevamente.</x-ui.alert>
            @elseif ($progress['terminal'] ?? false)
                <x-ui.alert class="mt-4" variant="success">El lote terminó correctamente.</x-ui.alert>
            @endif
        </div>
    @endif
</div>
