<section aria-labelledby="revision-history-heading" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Auditoría</p>
            <h2 id="revision-history-heading" class="mt-2 text-lg font-semibold text-slate-900">Historial de cambios sensibles</h2>
            <p class="mt-1 text-sm text-slate-600">Registro de quién modificó datos protegidos y cuáles fueron sus valores.</p>
        </div>

        @if ($revisions->isNotEmpty())
            <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $revisions->count() }} {{ $revisions->count() === 1 ? 'registro' : 'registros' }}</span>
        @endif
    </div>

    @if ($revisions->isEmpty())
        <div role="status" class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-center">
            <p class="font-semibold text-slate-800">Sin cambios sensibles</p>
            <p class="mt-1 text-sm text-slate-600">Cuando se modifiquen identidad, salario o cargo, el movimiento aparecerá acá.</p>
        </div>
    @else
        <ol class="mt-4 space-y-3 md:hidden">
            @foreach ($revisions as $revision)
                <li class="rounded-2xl border border-slate-200 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <span class="font-semibold text-slate-900">{{ $revision->field }}</span>
                        <time datetime="{{ $revision->created_at->toIso8601String() }}" class="text-xs text-slate-500">{{ $revision->created_at->format('d/m/Y H:i') }}</time>
                    </div>
                    <dl class="mt-3 grid gap-2 text-sm">
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Antes</dt><dd class="break-words text-slate-700">{{ $revision->old_value ?? '-' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Después</dt><dd class="break-words font-medium text-slate-900">{{ $revision->new_value ?? '-' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Responsable</dt><dd class="break-words text-slate-700">{{ $revision->user?->email ?? 'Sistema' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Motivo</dt><dd class="break-words text-slate-700">{{ $revision->reason ?? '-' }}</dd></div>
                    </dl>
                </li>
            @endforeach
        </ol>

        <div class="mt-4 hidden overflow-hidden rounded-2xl border border-slate-200 md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th scope="col" class="px-4 py-3">Fecha</th>
                            <th scope="col" class="px-4 py-3">Usuario</th>
                            <th scope="col" class="px-4 py-3">Campo</th>
                            <th scope="col" class="px-4 py-3">Valor anterior</th>
                            <th scope="col" class="px-4 py-3">Valor nuevo</th>
                            <th scope="col" class="px-4 py-3">Motivo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($revisions as $revision)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><time datetime="{{ $revision->created_at->toIso8601String() }}">{{ $revision->created_at->format('d/m/Y H:i') }}</time></td>
                                <td class="px-4 py-3 text-slate-700">{{ $revision->user?->email ?? 'Sistema' }}</td>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $revision->field }}</td>
                                <td class="max-w-48 break-words px-4 py-3 text-slate-700">{{ $revision->old_value ?? '-' }}</td>
                                <td class="max-w-48 break-words px-4 py-3 text-slate-900">{{ $revision->new_value ?? '-' }}</td>
                                <td class="max-w-48 break-words px-4 py-3 text-slate-700">{{ $revision->reason ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
