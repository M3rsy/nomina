<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f8f1ff_38%,_#ffffff_80%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Protección de datos
                    </p>
                    <h1 class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Respaldos</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">Exportá y descargá snapshots del sistema; restauración por ahora disponible para soporte técnico.</p>
                </div>

                <button
                    type="button"
                    wire:click="generate"
                    wire:loading.attr="disabled"
                    class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-900 bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Generar respaldo
                </button>
            </div>
        </header>

        @if ($message)
            <div
                class="rounded-3xl border p-4 text-sm
                @if ($messageType === 'success')
                    border-emerald-200 bg-emerald-50 text-emerald-700
                @elseif ($messageType === 'danger')
                    border-rose-200 bg-rose-50 text-rose-700
                @else
                    border-amber-200 bg-amber-50 text-amber-700
                @endif
                "
            >
                {{ $message }}
            </div>
        @endif

        <section class="rounded-3xl border border-sky-200 bg-sky-50 p-4 text-sky-700">
            <p>Los respaldos son globales e incluyen la información de todas las empresas. En MVP no se realizan respaldos por empresa.</p>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-slate-50/90 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Archivo</th>
                            <th class="px-4 py-3">Tamaño</th>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($files as $file)
                            <tr class="border-t border-slate-200">
                                <td class="px-4 py-3.5 text-sm font-semibold text-slate-900">{{ $file['name'] }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $file['size'] }}</td>
                                <td class="px-4 py-3.5 text-sm text-slate-700">{{ $file['modified'] }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex flex-wrap gap-2">
                                        <a
                                            href="{{ route('respaldos.download', ['path' => $file['path']]) }}"
                                            class="inline-flex min-h-9 items-center rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100"
                                        >
                                            Descargar
                                        </a>
                                        @if ($canRestore)
                                            <button
                                                type="button"
                                                wire:click="confirmRestore('{{ $file['path'] }}')"
                                                class="inline-flex min-h-9 items-center rounded-lg border border-amber-100 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 transition hover:bg-amber-100"
                                            >
                                                Restaurar
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-4 text-center text-sm text-slate-500">No hay respaldos disponibles.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @if ($showRestoreModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
                <h2 class="text-lg font-semibold text-slate-900">Restaurar respaldo</h2>
                <p class="mt-2 text-sm text-slate-600">La función de restauración no está implementada en MVP. Se registrará este intento.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="cancelRestore"
                        class="inline-flex min-h-10 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        wire:click="restore"
                        class="inline-flex min-h-10 items-center rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700"
                    >
                        Entendido
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
