@props([
    'heading',
    'description',
    'eyebrow' => 'Acceso seguro',
])

<div data-auth-shell class="min-h-[100svh] overflow-x-hidden bg-slate-50 text-slate-950">
    <div class="grid min-h-[100svh] lg:grid-cols-[minmax(22rem,0.9fr)_minmax(30rem,1.1fr)]">
        <aside class="relative overflow-hidden bg-slate-950 px-5 py-6 text-white sm:px-8 lg:flex lg:min-h-screen lg:flex-col lg:justify-between lg:px-10 lg:py-10 xl:px-14">
            <div>
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-indigo-500 text-white shadow-lg shadow-indigo-950/30">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h10A2.25 2.25 0 0 1 19.25 6v12A2.25 2.25 0 0 1 17 20.25H7A2.25 2.25 0 0 1 4.75 18V6A2.25 2.25 0 0 1 7 3.75Z" />
                            <path stroke-linecap="round" d="M8 8.25h8M8 12h3m2 0h3m-8 3.75h3m2 0h3" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-lg font-bold tracking-tight">{{ config('app.name', 'Nomina') }}</p>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-300">Centro operativo de nómina</p>
                    </div>
                </div>

                <div class="mt-5 max-w-lg lg:mt-20">
                    <p class="text-xl font-semibold tracking-tight text-white sm:text-2xl lg:text-4xl lg:leading-tight">
                        Asistencia y trazabilidad en cada jornada.
                    </p>
                    <p class="mt-3 hidden max-w-md text-sm leading-6 text-slate-300 lg:block">
                        Un espacio de control para convertir marcaciones, revisiones y bandas horarias en una nómina verificable.
                    </p>
                </div>
            </div>

            <div class="mt-10 hidden lg:block">
                <div class="border-y border-slate-700/80 py-5">
                    <div class="mb-4 flex items-center justify-between gap-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Bandas horarias</p>
                        <p class="text-xs text-slate-400">Ciclo de 24 horas</p>
                    </div>

                    <div class="grid h-2 grid-cols-[8fr_4fr_6fr_6fr] overflow-hidden rounded-full" aria-hidden="true">
                        <span class="bg-indigo-400"></span>
                        <span class="bg-amber-300"></span>
                        <span class="bg-orange-400"></span>
                        <span class="bg-rose-400"></span>
                    </div>

                    <ol class="mt-5 grid grid-cols-2 gap-x-6 gap-y-4" aria-label="Bandas horarias para el cálculo de nómina">
                        <li class="flex items-baseline justify-between gap-3 border-b border-slate-800 pb-3">
                            <span class="font-mono text-sm font-semibold text-white">06-14</span>
                            <span class="text-xs text-slate-400">Ordinaria</span>
                        </li>
                        <li class="flex items-baseline justify-between gap-3 border-b border-slate-800 pb-3">
                            <span class="font-mono text-sm font-semibold text-white">14-18</span>
                            <span class="text-xs text-amber-300">Extra 25%</span>
                        </li>
                        <li class="flex items-baseline justify-between gap-3 border-b border-slate-800 pb-3">
                            <span class="font-mono text-sm font-semibold text-white">18-00</span>
                            <span class="text-xs text-orange-300">Extra 50%</span>
                        </li>
                        <li class="flex items-baseline justify-between gap-3 border-b border-slate-800 pb-3">
                            <span class="font-mono text-sm font-semibold text-white">00-06</span>
                            <span class="text-xs text-rose-300">Extra 75%</span>
                        </li>
                    </ol>
                </div>

                <dl class="mt-6 grid grid-cols-3 gap-5 text-sm">
                    <div>
                        <dt class="font-semibold text-slate-100">Asistencia</dt>
                        <dd class="mt-1 text-xs leading-5 text-slate-400">Marcaciones centralizadas</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-100">Trazabilidad</dt>
                        <dd class="mt-1 text-xs leading-5 text-slate-400">Cambios verificables</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-100">Cálculo</dt>
                        <dd class="mt-1 text-xs leading-5 text-slate-400">Reglas por jornada</dd>
                    </div>
                </dl>
            </div>
        </aside>

        <section class="flex min-w-0 flex-col px-5 py-8 sm:px-8 sm:py-12 lg:justify-center lg:px-14 xl:px-24" aria-labelledby="auth-heading">
            <div class="mx-auto w-full max-w-lg">
                <header class="border-b border-slate-200 pb-6">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-indigo-700">{{ $eyebrow }}</p>
                    <h1 id="auth-heading" class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">{{ $heading }}</h1>
                    <p class="mt-3 max-w-md text-sm leading-6 text-slate-600 sm:text-base">{{ $description }}</p>
                </header>

                <div class="pt-7">
                    {{ $slot }}
                </div>

                <footer class="mt-10 border-t border-slate-200 pt-5 text-sm text-slate-500">
                    Desarrollado por CFV Technology
                </footer>
            </div>
        </section>
    </div>
</div>
