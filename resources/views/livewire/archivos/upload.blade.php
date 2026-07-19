<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <header class="mb-7 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="max-w-3xl">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600">Marcas de asistencia</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">Cargar archivo de marcas</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600 sm:text-base">
                Elegí el período y el archivo de origen. Al finalizar vas a ver el resultado de la validación; la nómina no se procesa automáticamente.
            </p>
        </div>

        <a href="{{ route('nomina.index') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
            Volver a períodos
        </a>
    </header>

    <form wire:submit="store" class="space-y-5">
        <section id="upload-step-period" aria-labelledby="upload-step-period-heading" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex items-start gap-4">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-600 text-sm font-bold text-white" aria-hidden="true">1</span>
                <div class="min-w-0 flex-1">
                    <h2 id="upload-step-period-heading" class="text-lg font-bold text-slate-950">Elegí el período</h2>
                    <p id="pay-period-help" class="mt-1 text-sm leading-6 text-slate-600">El archivo quedará asociado al período seleccionado.</p>

                    @if ($payPeriods->isEmpty())
                        <div role="status" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                            <p class="font-semibold">No hay períodos disponibles para carga.</p>
                            <p class="mt-1">Creá un período en borrador o elegí uno que todavía acepte archivos.</p>
                            <a href="{{ route('nomina.index') }}" class="mt-3 inline-flex font-semibold text-amber-950 underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">Crear un período</a>
                        </div>
                    @else
                        <label for="pay_period_id" class="mt-5 block text-sm font-semibold text-slate-800">Período de nómina</label>
                        <select
                            id="pay_period_id"
                            wire:model="pay_period_id"
                            required
                            aria-describedby="pay-period-help @error('pay_period_id') pay-period-error @enderror"
                            @error('pay_period_id') aria-invalid="true" @else aria-invalid="false" @enderror
                            class="mt-2 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                        >
                            <option value="">Seleccioná un período</option>
                            @foreach ($payPeriods as $period)
                                <option value="{{ $period->id }}">{{ $period->name ?? $period->slug }} · {{ $period->start_date->format('d/m/Y') }}–{{ $period->end_date->format('d/m/Y') }}</option>
                            @endforeach
                        </select>
                    @endif

                    @error('pay_period_id')
                        <p id="pay-period-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <section id="upload-step-file" aria-labelledby="upload-step-file-heading" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex items-start gap-4">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-600 text-sm font-bold text-white" aria-hidden="true">2</span>
                <div class="min-w-0 flex-1">
                    <h2 id="upload-step-file-heading" class="text-lg font-bold text-slate-950">Seleccioná el archivo</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">Usá el archivo exportado por el reloj de asistencia, sin superar los 5 MB.</p>

                    <div id="attendance-file-contract" class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 p-4 text-slate-700">
                            <span class="block font-semibold text-slate-950">GLG*.txt</span>
                            El nombre debe comenzar con GLG y usar la extensión .txt.
                        </div>
                        <div class="rounded-xl bg-slate-50 p-4 text-slate-700">
                            <span class="block font-semibold text-slate-950">ATTLOG: *.dat</span>
                            Para ATTLOG se acepta cualquier nombre con la extensión .dat.
                        </div>
                    </div>

                    <div class="relative mt-5 rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center transition hover:border-indigo-400 hover:bg-indigo-50/40 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-500/30 sm:p-8">
                        <input
                            id="upload"
                            type="file"
                            wire:model="upload"
                            accept=".txt,.dat"
                            required
                            aria-describedby="attendance-file-contract @error('upload') upload-error @enderror"
                            @error('upload') aria-invalid="true" @else aria-invalid="false" @enderror
                            class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                            style="opacity: 0;"
                        >
                        <svg class="mx-auto size-8 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14.5v3.75A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14.5" />
                        </svg>
                        <p class="mt-3 text-sm font-semibold text-slate-900">Arrastrá el archivo aquí o presioná para seleccionarlo</p>
                        <p class="mt-1 text-xs text-slate-500">GLG*.txt o ATTLOG *.dat · máximo 5 MB</p>
                    </div>

                    <p wire:loading wire:target="upload" role="status" aria-live="polite" class="mt-3 text-sm font-medium text-indigo-700">Cargando el archivo seleccionado...</p>

                    @if ($upload)
                        @php
                            $uploadSize = $upload->getSize();
                            $uploadSizeLabel = $uploadSize >= 1024 * 1024
                                ? number_format($uploadSize / (1024 * 1024), 1, ',', '.').' MB'
                                : number_format($uploadSize / 1024, 1, ',', '.').' KB';
                        @endphp
                        <div class="mt-4 flex min-w-0 items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4" aria-live="polite">
                            <svg class="mt-0.5 size-5 shrink-0 text-emerald-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M16.704 5.293a1 1 0 0 1 .003 1.414l-7.5 7.53a1 1 0 0 1-1.416.002l-3.5-3.5a1 1 0 0 1 1.414-1.414l2.792 2.792 6.793-6.821a1 1 0 0 1 1.414-.003Z" clip-rule="evenodd" />
                            </svg>
                            <div class="min-w-0 text-sm">
                                <p class="break-all font-semibold text-emerald-950">{{ $upload->getClientOriginalName() }}</p>
                                <p class="mt-0.5 text-emerald-800">{{ $uploadSizeLabel }}</p>
                            </div>
                        </div>
                    @endif

                    @error('upload')
                        <p id="upload-error" role="alert" class="mt-3 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <section id="upload-step-result" aria-labelledby="upload-step-result-heading" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex items-start gap-4">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-bold text-white" aria-hidden="true">3</span>
                <div class="min-w-0 flex-1">
                    <h2 id="upload-step-result-heading" class="text-lg font-bold text-slate-950">Revisá el resultado</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Al subir, se buscan empleados no encontrados, marcas fuera del rango de fechas y registros duplicados. Después te llevaremos al resultado del archivo para que lo revises.
                    </p>
                    <p class="mt-2 text-sm font-medium text-slate-800">Este paso valida las marcas; no procesa la nómina.</p>

                    <button
                        id="upload-submit"
                        type="submit"
                        @disabled(! $pay_period_id || ! $upload)
                        wire:loading.attr="disabled"
                        wire:target="upload,store"
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600 sm:w-auto"
                    >
                        <span wire:loading.remove wire:target="store">Subir y validar</span>
                        <span wire:loading wire:target="store">Validando archivo...</span>
                    </button>
                </div>
            </div>
        </section>
    </form>
</div>
