<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f8f1ff_38%,_#ffffff_80%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-3xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Empresas
                    </p>
                    <h1 class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Nueva empresa</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">Completá los datos para registrar una nueva compañía y comenzar su gestión.</p>
                </div>
                <a
                    href="/empresas"
                    class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Cancelar
                </a>
            </div>
        </header>

        <form wire:submit="save" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">Nombre</label>
                    <input
                        id="name"
                        type="text"
                        wire:model="name"
                        required
                        class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                    >
                    @error('name') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="legal_id" class="block text-sm font-medium text-slate-700">RTN</label>
                    <input
                        id="legal_id"
                        type="text"
                        wire:model="legal_id"
                        class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                    >
                    @error('legal_id') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center gap-2">
                    <input id="is_active" type="checkbox" wire:model="is_active" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <label for="is_active" class="block text-sm text-slate-700">Activa</label>
                </div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <button
                    type="submit"
                    class="inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-6 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>
