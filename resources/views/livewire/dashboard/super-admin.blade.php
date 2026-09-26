<div class="min-h-screen bg-surface-muted">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="dashboard-heading">
            <div class="h-2 bg-gradient-to-r from-brand via-brand-strong to-success" aria-hidden="true"></div>
            <div class="flex flex-col gap-6 px-6 py-7 sm:px-8 lg:flex-row lg:items-center lg:justify-between lg:py-8">
                <div class="max-w-3xl">
                    <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-brand-subtle px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-brand-strong">
                        <span class="h-2 w-2 rounded-full bg-success" aria-hidden="true"></span>
                        Vista ejecutiva
                    </div>
                    <h1 id="dashboard-heading" class="text-3xl font-black tracking-tight text-text sm:text-4xl">Panel super administrador</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-text-muted sm:text-base">Una lectura consolidada de la operación, la salud de nómina y las tendencias del período seleccionado.</p>
                </div>
                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-3xl bg-brand-subtle text-brand" aria-hidden="true">
                    <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9m5 10V5m5 14v-7m5 7V3M2 21h20" />
                    </svg>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="period-filter-heading">
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,0.9fr)] lg:items-end">
                <div>
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-subtle text-brand" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M3 9h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z" />
                            </svg>
                        </span>
                        <div>
                            <h2 id="period-filter-heading" class="text-lg font-extrabold text-text">Rango de análisis</h2>
                            <p class="mt-0.5 text-sm text-text-muted">Ajustá la ventana temporal de los indicadores operativos.</p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2" aria-label="Referencias rápidas de rango">
                        <span class="text-xs font-semibold uppercase tracking-wide text-text-muted">Referencias</span>
                        <span class="rounded-full border border-border bg-surface-muted px-3 py-1 text-xs font-semibold text-text-muted">7 días</span>
                        <span class="rounded-full border border-brand bg-brand-subtle px-3 py-1 text-xs font-semibold text-brand-strong">30 días</span>
                        <span class="rounded-full border border-border bg-surface-muted px-3 py-1 text-xs font-semibold text-text-muted">90 días</span>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <x-ui.input id="from" label="Desde" type="date" wire:model.live="from" />
                    <x-ui.input id="to" label="Hasta" type="date" wire:model.live="to" />
                </div>
            </div>
            <p class="mt-4 border-t border-border pt-4 text-xs leading-5 text-text-muted">Las tarjetas de organización muestran el estado actual. Los períodos de nómina usan inclusión completa y límites inclusivos. La tendencia mensual usa la fecha de cada resultado con límites inclusivos.</p>
        </section>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicadores de organización">
            <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-text-muted">Empresas activas</p>
                        <p class="mt-2 text-3xl font-black tracking-tight text-text">{{ $activeCompanies }}</p>
                    </div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-success-subtle text-success-strong" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 10h.01M9 14h.01M15 10h.01M15 14h.01M9 18h6" /></svg>
                    </span>
                </div>
                <p class="mt-4 text-xs leading-5 text-text-muted">Con acceso productivo en el alcance actual.</p>
                <div class="mt-4 h-1 rounded-full bg-success" aria-hidden="true"></div>
            </article>

            <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-text-muted">Empresas inactivas</p>
                        <p class="mt-2 text-3xl font-black tracking-tight text-text">{{ $inactiveCompanies }}</p>
                    </div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-danger-subtle text-danger-strong" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z" /></svg>
                    </span>
                </div>
                <p class="mt-4 text-xs leading-5 text-text-muted">Sin acceso operativo en este momento.</p>
                <div class="mt-4 h-1 rounded-full bg-danger" aria-hidden="true"></div>
            </article>

            <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-text-muted">Usuarios activos</p>
                        <p class="mt-2 text-3xl font-black tracking-tight text-text">{{ $activeUsers }}</p>
                    </div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-subtle text-brand-strong" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.9m-2-10a4 4 0 0 1 0 7.8" /></svg>
                    </span>
                </div>
                <p class="mt-4 text-xs leading-5 text-text-muted">Con credenciales vigentes.</p>
                <div class="mt-4 h-1 rounded-full bg-brand" aria-hidden="true"></div>
            </article>

            <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-text-muted">Empleados activos</p>
                        <p class="mt-2 text-3xl font-black tracking-tight text-text">{{ $activeEmployees }}</p>
                    </div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-warning-subtle text-warning-strong" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6 10v-2a6 6 0 0 0-12 0v2m14-10 2 2 3-4" /></svg>
                    </span>
                </div>
                <p class="mt-4 text-xs leading-5 text-text-muted">Listos para cómputo de nómina.</p>
                <div class="mt-4 h-1 rounded-full bg-warning" aria-hidden="true"></div>
            </article>
        </section>

        <section class="rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="payroll-overview-heading">
            <div class="flex flex-col gap-4 border-b border-border px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">Operación</p>
                    <h2 id="payroll-overview-heading" class="mt-1 text-xl font-black text-text">Resumen operativo de nómina</h2>
                    @if (! empty($payrollOverview))
                        <p class="mt-1 text-sm text-text-muted">Empresa activa: <span class="font-bold text-text">{{ $payrollOverview['company_name'] }}</span></p>
                    @endif
                </div>
                @if (! empty($payrollOverview))
                    @can('viewAny', App\Models\PayPeriod::class)
                        <x-ui.button :href="route('nomina.index')" variant="secondary">Ver períodos de nómina</x-ui.button>
                    @endcan
                @endif
            </div>

            <div class="p-5 sm:p-6">
                @if (empty($payrollOverview))
                    <x-ui.empty-state title="Seleccioná una empresa para consultar sus períodos de nómina.">
                        Este resumen nunca combina empresas. Usá el selector de empresa de la barra superior.
                    </x-ui.empty-state>
                @elseif ($payrollOverview['total'] === 0)
                    @if ($payrollOverview['has_periods'])
                        <x-ui.empty-state title="No hay períodos que coincidan con el rango seleccionado.">
                            Ajustá las fechas Desde y Hasta para ampliar la consulta.
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state title="Todavía no hay períodos de nómina para esta empresa.">
                            Abrí la sección de nómina para registrar o consultar períodos.
                        </x-ui.empty-state>
                    @endif
                @else
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        @php
                            $payrollStates = [
                                ['label' => 'Total', 'value' => $payrollOverview['total'], 'color' => 'bg-brand', 'surface' => 'bg-brand-subtle'],
                                ['label' => 'En preparación', 'value' => $payrollOverview['preparation'], 'color' => 'bg-warning', 'surface' => 'bg-warning-subtle'],
                                ['label' => 'Procesando', 'value' => $payrollOverview['processing'], 'color' => 'bg-brand', 'surface' => 'bg-brand-subtle'],
                                ['label' => 'Completadas', 'value' => $payrollOverview['completed'], 'color' => 'bg-success', 'surface' => 'bg-success-subtle'],
                                ['label' => 'Validación con errores', 'value' => $payrollOverview['validation_failed'], 'color' => 'bg-danger', 'surface' => 'bg-danger-subtle'],
                                ['label' => 'Canceladas', 'value' => $payrollOverview['cancelled'], 'color' => 'bg-text-muted', 'surface' => 'bg-surface-muted'],
                            ];
                        @endphp
                        @foreach ($payrollStates as $state)
                            <div class="rounded-2xl border border-border p-4 {{ $state['surface'] }}">
                                <div class="mb-3 h-1.5 w-8 rounded-full {{ $state['color'] }}" aria-hidden="true"></div>
                                <p class="text-2xl font-black text-text">{{ $state['value'] }}</p>
                                <p class="mt-1 text-xs font-semibold leading-4 text-text-muted">{{ $state['label'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($payrollOverview['unknown'] > 0)
                        <x-ui.alert class="mt-4" title="Otros estados registrados">
                            <span class="font-semibold">{{ $payrollOverview['unknown'] }}</span> períodos usan un estado no agrupado.
                        </x-ui.alert>
                    @endif
                @endif
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            @can('audit.view')
                <section class="relative overflow-hidden rounded-3xl bg-text p-6 text-white shadow-sm" aria-labelledby="audit-handoff-heading">
                    <div class="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-brand opacity-40" aria-hidden="true"></div>
                    <div class="relative">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand text-white" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 11 11 13 15 9m4-4v14H5V5m3 0V3h8v2" /></svg>
                        </span>
                        <p class="mt-6 text-xs font-bold uppercase tracking-[0.16em] text-warning">Trazabilidad</p>
                        <h2 id="audit-handoff-heading" class="mt-2 text-2xl font-black">Historial de actividad</h2>
                        <p class="mt-3 text-sm leading-6 text-white/75">Consultá los eventos detallados, cambios y acciones administrativas en Auditoría.</p>
                        <div class="mt-6">
                            <x-ui.button :href="route('auditoria.index')">Ver historial en Auditoría</x-ui.button>
                        </div>
                    </div>
                </section>
            @endcan

            <section class="min-w-0 rounded-3xl border border-border bg-surface shadow-sm @can('audit.view') lg:col-span-2 @else lg:col-span-3 @endcan" aria-labelledby="payroll-trends-heading">
                <div class="border-b border-border px-5 py-5 sm:px-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">Evolución</p>
                    <h2 id="payroll-trends-heading" class="mt-1 text-xl font-black text-text">Tendencia mensual de nómina</h2>
                    <p class="mt-1 text-sm text-text-muted">Resultados y horas de la empresa activa dentro del rango actual.</p>
                </div>
                <div class="p-5 sm:p-6">
                    @if (is_null($payrollTrends))
                        <x-ui.empty-state title="Seleccioná una empresa activa para consultar su tendencia mensual de nómina.">
                            La tendencia se calcula solamente para la empresa seleccionada.
                        </x-ui.empty-state>
                    @elseif (empty($payrollTrends))
                        <x-ui.empty-state title="No hay resultados de nómina para la empresa activa en el rango de fechas actual.">
                            Ajustá el rango para consultar otros resultados.
                        </x-ui.empty-state>
                    @else
                        <ol class="space-y-5">
                            @foreach ($payrollTrends as $trend)
                                <li class="min-w-0">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                        <time datetime="{{ $trend['month'] }}" class="font-extrabold text-text">{{ $trend['label'] }}</time>
                                        <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs">
                                            <div class="flex gap-1"><dt class="text-text-muted">Registros de resultado</dt><dd class="font-bold text-text">{{ $trend['entries'] }}</dd></div>
                                            <div class="flex gap-1"><dt class="text-text-muted">Horas ordinarias</dt><dd class="font-bold text-text">{{ number_format($trend['ordinary_hours'], 2, '.', '') }} h</dd></div>
                                            <div class="flex gap-1"><dt class="text-text-muted">Horas extras</dt><dd class="font-bold text-text">{{ number_format($trend['extra_hours'], 2, '.', '') }} h</dd></div>
                                        </dl>
                                    </div>
                                    <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true">
                                        <div class="h-full rounded-full bg-gradient-to-r from-brand to-success" @style(['width: '.$trend['bar_width'].'%'])></div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
