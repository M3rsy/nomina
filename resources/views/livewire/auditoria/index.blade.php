<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f8f1ff_38%,_#ffffff_80%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Seguridad y trazabilidad
                    </p>
                    <h1 class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Auditoría</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">Consultá eventos históricos con filtros por tipo, usuario y rango de fecha.</p>
                </div>
            </div>
        </header>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap gap-3 items-start">
                <label for="type" class="min-w-52">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Tipo de evento</span>
                    <select id="type" wire:model.live="type" class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label for="user" class="min-w-60">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Usuario (correo)</span>
                    <input id="user" type="text" wire:model.live.debounce.300ms="user" placeholder="correo@ejemplo.com" class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                </label>

                <label for="from" class="min-w-40">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Desde</span>
                    <input id="from" type="date" wire:model.live="from" class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                </label>

                <label for="to" class="min-w-40">
                    <span class="mb-1 block text-xs font-medium text-slate-700">Hasta</span>
                    <input id="to" type="date" wire:model.live="to" class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                </label>
            </div>

            <div class="flex flex-wrap gap-2 text-xs">
                @if ($type !== 'all')
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-indigo-700">Tipo: <span class="ml-1 font-semibold">{{ $types[$type] ?? $type }}</span></span>
                @endif
                @if (! empty($user))
                    <span class="inline-flex items-center rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-violet-700">Usuario: <span class="ml-1 font-semibold">{{ $user }}</span></span>
                @endif
                @if (! empty($from))
                    <span class="inline-flex items-center rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-sky-700">Desde: <span class="ml-1 font-semibold">{{ $from }}</span></span>
                @endif
                @if (! empty($to))
                    <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-emerald-700">Hasta: <span class="ml-1 font-semibold">{{ $to }}</span></span>
                @endif
                @if ($type === 'all' && empty($user) && empty($from) && empty($to))
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-slate-600">Sin filtros activos</span>
                @endif
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-slate-50/90 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Empresa</th>
                            <th class="px-4 py-3">Usuario</th>
                            <th class="px-4 py-3">Descripción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            <tr class="border-t border-slate-200">
                                <td class="px-4 py-3.5 text-sm text-slate-900">{{ $entry->createdAt->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $entry->typeLabel }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $entry->companyName ?? 'N/A' }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $entry->userEmail ?? 'N/A' }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $entry->description }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-4 text-center text-sm text-slate-500">No hay eventos de auditoría.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pt-1">
            {{ $entries->links() }}
        </div>
    </div>
</div>
