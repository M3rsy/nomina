<div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Auditoría</p>
            <h2 class="mt-2 text-lg font-semibold text-slate-900">Historial de cambios sensibles</h2>
        </div>

        @if (! $revisions->isEmpty())
            <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $revisions->count() }} registros</span>
        @endif
    </div>

    @if ($revisions->isEmpty())
        <p role="status" class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-600">No hay cambios registrados.</p>
    @else
        <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Usuario</th>
                            <th class="px-4 py-3">Campo</th>
                            <th class="px-4 py-3">Valor anterior</th>
                            <th class="px-4 py-3">Valor nuevo</th>
                            <th class="px-4 py-3">Motivo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($revisions as $revision)
                            <tr>
                                <td class="px-4 py-3 text-slate-700">{{ $revision->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ $revision->user?->email ?? 'Sistema' }}</td>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $revision->field }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ $revision->old_value ?? '-' }}</td>
                                <td class="px-4 py-3 text-slate-900">{{ $revision->new_value ?? '-' }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ $revision->reason ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
