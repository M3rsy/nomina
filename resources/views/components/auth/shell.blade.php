@props([
    'heading',
    'description',
    'eyebrow' => 'Acceso seguro',
])

<div data-auth-shell class="min-h-[100svh] overflow-x-hidden bg-slate-50 text-slate-950">
    <div class="grid min-h-[100svh] lg:grid-cols-[minmax(0,1.08fr)_minmax(30rem,0.92fr)]">
        <aside class="relative isolate overflow-hidden bg-[#071426] px-5 py-6 text-white sm:px-8 lg:flex lg:min-h-screen lg:flex-col lg:px-10 lg:py-8 xl:px-14">
            <div
                class="pointer-events-none absolute inset-0 -z-20 opacity-30"
                style="background-image: linear-gradient(rgba(148, 163, 184, 0.09) 1px, transparent 1px), linear-gradient(90deg, rgba(148, 163, 184, 0.09) 1px, transparent 1px); background-size: 42px 42px;"
                aria-hidden="true"
            ></div>
            <div class="pointer-events-none absolute -left-24 top-28 -z-10 size-[34rem] rounded-full bg-cyan-400/10 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-32 right-0 -z-10 size-[30rem] rounded-full bg-indigo-500/15 blur-3xl" aria-hidden="true"></div>

            <header class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl border border-cyan-300/20 bg-cyan-300/10 text-cyan-300 shadow-lg shadow-slate-950/40">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.75 3.75h12.5A2.25 2.25 0 0 1 20.5 6v12a2.25 2.25 0 0 1-2.25 2.25H5.75A2.25 2.25 0 0 1 3.5 18V6a2.25 2.25 0 0 1 2.25-2.25Z" />
                            <path stroke-linecap="round" d="M7.5 8h9M7.5 12h3m3 0h3m-9 4h3m3 0h3" />
                        </svg>
                    </span>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-lg font-bold tracking-tight">Nómina</p>
                            <span class="rounded-full border border-slate-600/80 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-300">Executive v2.4</span>
                        </div>
                        <p class="mt-0.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-cyan-300/80">Centro Operativo de Nómina</p>
                    </div>
                </div>

                <div class="hidden items-center gap-2 rounded-full border border-emerald-300/20 bg-emerald-300/10 px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.14em] text-emerald-300 sm:flex">
                    <span class="size-1.5 rounded-full bg-emerald-300 shadow-[0_0_10px_rgba(110,231,183,0.9)]"></span>
                    Cluster SLA 99.9%
                </div>
            </header>

            <div class="mt-8 max-w-2xl lg:mt-12 xl:mt-16">
                <p class="text-3xl font-semibold leading-tight tracking-[-0.035em] text-white sm:text-4xl xl:text-5xl xl:leading-[1.08]">
                    Asistencia y trazabilidad en cada jornada.
                </p>
                <p class="mt-4 max-w-xl text-sm leading-6 text-slate-300 sm:text-base">
                    Una operación centralizada para registrar marcaciones, auditar novedades y convertir cada turno en una nómina verificable.
                </p>

                <ul class="mt-6 flex flex-wrap gap-2" aria-label="Garantías operativas">
                    <li class="rounded-full border border-slate-700 bg-slate-900/60 px-3 py-1.5 text-xs font-medium text-slate-200">Operación continua</li>
                    <li class="rounded-full border border-slate-700 bg-slate-900/60 px-3 py-1.5 text-xs font-medium text-slate-200">Auditoría de cambios</li>
                    <li class="rounded-full border border-slate-700 bg-slate-900/60 px-3 py-1.5 text-xs font-medium text-slate-200">Reglas verificables</li>
                </ul>
            </div>

            <div class="mt-8 hidden lg:block xl:mt-10">
                <section class="rounded-2xl border border-slate-700/70 bg-slate-900/55 p-5 shadow-2xl shadow-slate-950/20 backdrop-blur-sm" aria-labelledby="schedule-heading">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.22em] text-cyan-300">Cobertura operativa</p>
                            <h2 id="schedule-heading" class="mt-1 text-sm font-semibold text-white">Bandas horarias · 24h</h2>
                        </div>
                        <p class="font-mono text-[11px] text-slate-400">00:00 — 24:00</p>
                    </div>

                    <div class="mt-4 grid h-2 grid-cols-[6fr_8fr_4fr_6fr] gap-0.5 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                        <span class="bg-violet-400"></span>
                        <span class="bg-cyan-400"></span>
                        <span class="bg-amber-300"></span>
                        <span class="bg-orange-400"></span>
                    </div>

                    <ol class="mt-4 grid grid-cols-4 gap-2" aria-label="Bandas horarias para el cálculo de nómina">
                        <li class="rounded-xl border border-slate-700/70 bg-slate-950/40 p-3">
                            <span class="block font-mono text-sm font-bold text-white">00-06</span>
                            <span class="mt-1 block text-[10px] uppercase tracking-wide text-violet-300">Extra 75%</span>
                        </li>
                        <li class="rounded-xl border border-slate-700/70 bg-slate-950/40 p-3">
                            <span class="block font-mono text-sm font-bold text-white">06-14</span>
                            <span class="mt-1 block text-[10px] uppercase tracking-wide text-cyan-300">Ordinaria</span>
                        </li>
                        <li class="rounded-xl border border-slate-700/70 bg-slate-950/40 p-3">
                            <span class="block font-mono text-sm font-bold text-white">14-18</span>
                            <span class="mt-1 block text-[10px] uppercase tracking-wide text-amber-300">Extra 25%</span>
                        </li>
                        <li class="rounded-xl border border-slate-700/70 bg-slate-950/40 p-3">
                            <span class="block font-mono text-sm font-bold text-white">18-00</span>
                            <span class="mt-1 block text-[10px] uppercase tracking-wide text-orange-300">Extra 50%</span>
                        </li>
                    </ol>
                </section>

                <dl class="mt-5 grid grid-cols-3 divide-x divide-slate-700/70 border-y border-slate-700/70 py-4">
                    <div class="pr-5">
                        <dt class="flex items-center gap-2 text-sm font-semibold text-slate-100">
                            <span class="size-1.5 rounded-full bg-cyan-300"></span>
                            Asistencia
                        </dt>
                        <dd class="mt-1 text-xs leading-5 text-slate-400">Marcaciones centralizadas</dd>
                    </div>
                    <div class="px-5">
                        <dt class="flex items-center gap-2 text-sm font-semibold text-slate-100">
                            <span class="size-1.5 rounded-full bg-indigo-300"></span>
                            Trazabilidad
                        </dt>
                        <dd class="mt-1 text-xs leading-5 text-slate-400">Cambios con evidencia</dd>
                    </div>
                    <div class="pl-5">
                        <dt class="flex items-center gap-2 text-sm font-semibold text-slate-100">
                            <span class="size-1.5 rounded-full bg-amber-300"></span>
                            Cálculo
                        </dt>
                        <dd class="mt-1 text-xs leading-5 text-slate-400">Reglas por jornada</dd>
                    </div>
                </dl>
            </div>
        </aside>

        <section class="flex min-w-0 flex-col bg-[#f7f9fc] px-5 py-6 sm:px-8 lg:min-h-screen lg:px-12 lg:py-8 xl:px-16" aria-labelledby="auth-heading">
            <div class="flex items-center justify-between gap-4 border-b border-slate-200/80 pb-4 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                <p class="flex items-center gap-2">
                    <span class="grid size-6 place-items-center rounded-full bg-emerald-100 text-emerald-700">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 10V7.75a4.5 4.5 0 0 1 9 0V10m-10 0h11a1.5 1.5 0 0 1 1.5 1.5v7A1.5 1.5 0 0 1 17.5 20h-11A1.5 1.5 0 0 1 5 18.5v-7A1.5 1.5 0 0 1 6.5 10Z" />
                        </svg>
                    </span>
                    Conexión segura
                </p>
                <p class="hidden sm:block">Sistema operativo · En línea</p>
            </div>

            <div class="flex flex-1 items-center py-10 lg:py-8">
                <div class="mx-auto w-full max-w-md">
                    <header>
                        <p class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.18em] text-indigo-700">
                            <span class="size-1.5 rounded-full bg-indigo-600"></span>
                            {{ $eyebrow }}
                        </p>
                        <h1 id="auth-heading" class="mt-5 text-3xl font-bold tracking-[-0.03em] text-slate-950 sm:text-4xl">{{ $heading }}</h1>
                        <p class="mt-3 max-w-md text-sm leading-6 text-slate-600 sm:text-base">{{ $description }}</p>
                    </header>

                    <div class="mt-8">
                        {{ $slot }}
                    </div>
                </div>
            </div>

            <footer class="border-t border-slate-200/80 pt-4 text-xs text-slate-500">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p>Desarrollado por <span class="font-semibold text-slate-700">CFV Technology</span></p>
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span>Soporte</span>
                        <span aria-hidden="true">·</span>
                        <span>Privacidad</span>
                        <span aria-hidden="true">·</span>
                        <span>Executive v2.4</span>
                    </p>
                </div>
            </footer>
        </section>
    </div>
</div>
