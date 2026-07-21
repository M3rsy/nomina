<div class="min-h-screen bg-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-blue-500 via-indigo-500 to-cyan-500"></div>
            <div class="mb-5 space-y-2">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Panel super administrador</h1>
                <p class="text-sm leading-6 text-slate-600">Vista consolidada de operación, salud de nómina y tendencias por periodo.</p>
            </div>

            <section aria-labelledby="period-filter-heading" class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                <h2 id="period-filter-heading" class="sr-only">Periodo activo</h2>
                <div class="flex flex-wrap gap-4 items-end">
                    <label for="from" class="flex flex-1 flex-col gap-2 text-sm text-slate-700">
                        <span class="font-medium">Desde</span>
                        <input id="from" type="date" wire:model.live="from" class="rounded-lg border-slate-300 bg-white px-3 py-2 shadow-sm transition duration-150 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </label>

                    <label for="to" class="flex flex-1 flex-col gap-2 text-sm text-slate-700">
                        <span class="font-medium">Hasta</span>
                        <input id="to" type="date" wire:model.live="to" class="rounded-lg border-slate-300 bg-white px-3 py-2 shadow-sm transition duration-150 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </label>
                </div>
                <p class="mt-3 text-xs leading-5 text-slate-600">Las tarjetas de organización muestran el estado actual. Los períodos de nómina usan inclusión completa y límites inclusivos. La tendencia mensual usa la fecha de cada resultado con límites inclusivos.</p>
            </section>
        </div>

        <section class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4" aria-label="Indicadores de organización">
            <article class="rounded-2xl border border-emerald-200 bg-white p-4 shadow-sm">
                <p class="text-sm font-medium text-slate-600">Empresas activas</p>
                <p class="mt-2 text-3xl font-bold text-emerald-700">{{ $activeCompanies }}</p>
                <p class="mt-2 text-xs text-emerald-900/70">Con acceso productivo en el alcance actual.</p>
            </article>
            <article class="rounded-2xl border border-rose-200 bg-white p-4 shadow-sm">
                <p class="text-sm font-medium text-slate-600">Empresas inactivas</p>
                <p class="mt-2 text-3xl font-bold text-rose-700">{{ $inactiveCompanies }}</p>
                <p class="mt-2 text-xs text-rose-900/70">Sin acceso operativo en este momento.</p>
            </article>
            <article class="rounded-2xl border border-blue-200 bg-white p-4 shadow-sm">
                <p class="text-sm font-medium text-slate-600">Usuarios activos</p>
                <p class="mt-2 text-3xl font-bold text-blue-700">{{ $activeUsers }}</p>
                <p class="mt-2 text-xs text-blue-900/70">Con credenciales vigentes.</p>
            </article>
            <article class="rounded-2xl border border-indigo-200 bg-white p-4 shadow-sm">
                <p class="text-sm font-medium text-slate-600">Empleados activos</p>
                <p class="mt-2 text-3xl font-bold text-indigo-700">{{ $activeEmployees }}</p>
                <p class="mt-2 text-xs text-indigo-900/70">Listos para cómputo de nómina.</p>
            </article>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="payroll-overview-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="payroll-overview-heading" class="text-xl font-semibold text-slate-900">Resumen operativo de nómina</h2>
                    @if (! empty($payrollOverview))
                        <p class="mt-1 text-sm text-slate-600">Empresa activa: <span class="font-medium text-slate-800">{{ $payrollOverview['company_name'] }}</span></p>
                    @endif
                </div>

                @if (! empty($payrollOverview))
                    @can('viewAny', App\Models\PayPeriod::class)
                        <a href="{{ route('nomina.index') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg border border-blue-200 bg-white px-4 py-2 text-sm font-semibold text-blue-700 shadow-sm transition hover:border-blue-300 hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            Ver períodos de nómina
                        </a>
                    @endcan
                @endif
            </div>

            @if (empty($payrollOverview))
                <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-5">
                    <p class="font-semibold text-slate-900">Seleccioná una empresa para consultar sus períodos de nómina.</p>
                    <p class="mt-1 text-sm text-slate-600">Este resumen nunca combina empresas. Usá el selector de empresa de la barra superior.</p>
                </div>
            @elseif ($payrollOverview['total'] === 0)
                <div class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5">
                    @if ($payrollOverview['has_periods'])
                        <p class="font-semibold text-slate-900">No hay períodos que coincidan con el rango seleccionado.</p>
                        <p class="mt-1 text-sm text-slate-600">Ajustá las fechas Desde y Hasta para ampliar la consulta.</p>
                    @else
                        <p class="font-semibold text-slate-900">Todavía no hay períodos de nómina para esta empresa.</p>
                        <p class="mt-1 text-sm text-slate-600">Abrí la sección de nómina para registrar o consultar períodos.</p>
                    @endif
                </div>
            @else
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-950/90 p-4 text-white shadow-sm">
                        <span class="inline-flex rounded-full bg-white/10 px-2.5 py-1 text-xs font-semibold text-white/90">Total</span>
                        <p class="mt-4 text-3xl font-bold">{{ $payrollOverview['total'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                        <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">En preparación</span>
                        <p class="mt-4 text-3xl font-bold text-amber-900">{{ $payrollOverview['preparation'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 shadow-sm">
                        <span class="inline-flex rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800">Procesando</span>
                        <p class="mt-4 text-3xl font-bold text-blue-900">{{ $payrollOverview['processing'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
                        <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Completadas</span>
                        <p class="mt-4 text-3xl font-bold text-emerald-900">{{ $payrollOverview['completed'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm">
                        <span class="inline-flex rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-800">Validación con errores</span>
                        <p class="mt-4 text-3xl font-bold text-rose-900">{{ $payrollOverview['validation_failed'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-300 bg-slate-50 p-4 shadow-sm">
                        <span class="inline-flex rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700">Canceladas</span>
                        <p class="mt-4 text-3xl font-bold text-slate-900">{{ $payrollOverview['cancelled'] }}</p>
                    </div>
                </div>

                @if ($payrollOverview['unknown'] > 0)
                    <div class="mt-4 flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        <span>Otros estados registrados</span>
                        <span class="inline-flex min-w-8 justify-center rounded-full bg-slate-200 px-2.5 py-1 font-semibold text-slate-800">{{ $payrollOverview['unknown'] }}</span>
                    </div>
                @endif
            @endif
        </section>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
            @can('audit.view')
                <section class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">Historial de actividad</h2>
                            <p class="mt-1 text-sm text-slate-600">Consultá los eventos detallados en la sección Auditoría.</p>
                        </div>
                        <a href="{{ route('auditoria.index') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            Ver historial en Auditoría
                        </a>
                    </div>
                </section>
            @endcan

            <section class="min-w-0 rounded-2xl bg-white p-5 shadow-sm" aria-labelledby="payroll-trends-heading">
                <div class="mb-4">
                    <h2 id="payroll-trends-heading" class="text-lg font-semibold text-slate-900">Tendencia mensual de nómina</h2>
                    <p class="mt-1 text-sm text-slate-600">Muestra registros de resultados y horas de la empresa activa dentro del rango actual de fechas de resultados.</p>
                </div>

                @if (is_null($payrollTrends))
                    <p class="text-sm font-medium text-slate-700">Seleccioná una empresa activa para consultar su tendencia mensual de nómina.</p>
                @elseif (empty($payrollTrends))
                    <p class="text-sm font-medium text-slate-700" role="status">No hay resultados de nómina para la empresa activa en el rango de fechas actual.</p>
                @else
                    <ol class="space-y-3">
                        @foreach ($payrollTrends as $trend)
                            <li class="min-w-0 rounded-xl border border-slate-200 p-4">
                                <time datetime="{{ $trend['month'] }}" class="font-semibold text-slate-900">{{ $trend['label'] }}</time>
                                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                    <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-cyan-500" style="width: {{ $trend['bar_width'] }}%"></div>
                                </div>
                                <dl class="mt-3 grid min-w-0 grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                                    <div class="min-w-0">
                                        <dt class="text-slate-500">Registros de resultado</dt>
                                        <dd class="mt-1 break-words font-semibold text-slate-900">{{ $trend['entries'] }}</dd>
                                    </div>
                                    <div class="min-w-0">
                                        <dt class="text-slate-500">Horas ordinarias</dt>
                                        <dd class="mt-1 break-words font-semibold text-slate-900">{{ number_format($trend['ordinary_hours'], 2, '.', '') }}</dd>
                                    </div>
                                    <div class="min-w-0">
                                        <dt class="text-slate-500">Horas extras</dt>
                                        <dd class="mt-1 break-words font-semibold text-slate-900">{{ number_format($trend['extra_hours'], 2, '.', '') }}</dd>
                                    </div>
                                </dl>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    </div>
</div>
