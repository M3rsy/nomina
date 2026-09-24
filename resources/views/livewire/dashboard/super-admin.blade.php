<div class="min-h-screen bg-surface-muted">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section data-dashboard-section="hero" class="relative overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="super-dashboard-heading">
            <div class="h-1.5 bg-gradient-to-r from-dashboard-accent via-brand to-dashboard-info" aria-hidden="true"></div>
            <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] lg:p-8">
                <div class="min-w-0">
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center rounded-full bg-dashboard-accent-subtle px-3 py-1 text-xs font-bold uppercase tracking-wider text-dashboard-accent-strong">Centro de control</span>
                        <span class="inline-flex items-center rounded-full bg-success-subtle px-3 py-1 text-xs font-semibold text-success-strong">Super administración</span>
                    </div>
                    <h1 id="super-dashboard-heading" class="mt-4 text-3xl font-black tracking-tight text-text sm:text-4xl">Panel super administrador</h1>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-text-muted">Vista consolidada de operación, salud de nómina y tendencias por período.</p>

                    <div class="mt-6 rounded-2xl border border-dashboard-info/30 bg-dashboard-info-subtle p-4">
                        <p class="text-xs font-bold uppercase tracking-wider text-dashboard-info-strong">Alcance empresarial</p>
                        @if (! empty($payrollOverview))
                            <p class="mt-1 text-sm text-text">La operación de nómina y sus tendencias corresponden únicamente a <span class="font-bold">{{ $payrollOverview['company_name'] }}</span>.</p>
                        @else
                            <p class="mt-1 text-sm text-text">Los indicadores de organización abarcan el entorno. Seleccioná una empresa para consultar nómina sin combinar datos entre compañías.</p>
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl border border-border bg-surface-muted p-5" aria-labelledby="period-filter-heading">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-subtle text-brand" aria-hidden="true">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z" /></svg>
                        </span>
                        <div>
                            <h2 id="period-filter-heading" class="font-bold text-text">Rango de análisis</h2>
                            <p class="mt-1 text-sm text-text-muted">Períodos y resultados usan límites inclusivos.</p>
                        </div>
                    </div>
                    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        <x-ui.input id="from" label="Desde" type="date" wire:model.live="from" />
                        <x-ui.input id="to" label="Hasta" type="date" wire:model.live="to" />
                    </div>
                    <p class="mt-4 text-xs leading-5 text-text-muted">Las tarjetas de organización muestran el estado actual. Los períodos de nómina usan inclusión completa y límites inclusivos. La tendencia mensual usa la fecha de cada resultado con límites inclusivos.</p>
                </div>
            </div>
        </section>

        <section data-dashboard-section="organization-kpis" aria-labelledby="organization-kpis-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-brand">Panorama general</p>
                    <h2 id="organization-kpis-heading" class="mt-1 text-xl font-bold text-text">Indicadores de organización</h2>
                </div>
                <span class="rounded-full border border-border bg-surface px-3 py-1 text-xs font-semibold text-text-muted">Estado actual</span>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-success-subtle text-success-strong" aria-hidden="true"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 10h.01M15 10h.01M9 14h.01M15 14h.01" /></svg></span>
                        <span class="rounded-full bg-success-subtle px-2.5 py-1 text-xs font-bold text-success-strong">Operativas</span>
                    </div>
                    <p class="mt-5 text-sm font-medium text-text-muted">Empresas activas</p>
                    <p class="mt-1 text-3xl font-black text-text">{{ $activeCompanies }}</p>
                    <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true"><div class="h-full w-full rounded-full bg-success"></div></div>
                    <p class="mt-3 text-xs text-text-muted">Con acceso productivo en el alcance actual.</p>
                </article>

                <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-danger-subtle text-danger-strong" aria-hidden="true"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z" /></svg></span>
                        <span class="rounded-full bg-danger-subtle px-2.5 py-1 text-xs font-bold text-danger-strong">Revisar</span>
                    </div>
                    <p class="mt-5 text-sm font-medium text-text-muted">Empresas inactivas</p>
                    <p class="mt-1 text-3xl font-black text-text">{{ $inactiveCompanies }}</p>
                    <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true"><div class="h-full w-full rounded-full bg-danger"></div></div>
                    <p class="mt-3 text-xs text-text-muted">Sin acceso operativo en este momento.</p>
                </article>

                <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-brand-subtle text-brand" aria-hidden="true"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg></span>
                        <span class="rounded-full bg-brand-subtle px-2.5 py-1 text-xs font-bold text-brand-strong">Habilitados</span>
                    </div>
                    <p class="mt-5 text-sm font-medium text-text-muted">Usuarios activos</p>
                    <p class="mt-1 text-3xl font-black text-text">{{ $activeUsers }}</p>
                    <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true"><div class="h-full w-full rounded-full bg-brand"></div></div>
                    <p class="mt-3 text-xs text-text-muted">Con credenciales vigentes.</p>
                </article>

                <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-dashboard-info-subtle text-dashboard-info-strong" aria-hidden="true"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" /></svg></span>
                        <span class="rounded-full bg-dashboard-info-subtle px-2.5 py-1 text-xs font-bold text-dashboard-info-strong">Disponibles</span>
                    </div>
                    <p class="mt-5 text-sm font-medium text-text-muted">Empleados activos</p>
                    <p class="mt-1 text-3xl font-black text-text">{{ $activeEmployees }}</p>
                    <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true"><div class="h-full w-full rounded-full bg-dashboard-info"></div></div>
                    <p class="mt-3 text-xs text-text-muted">Listos para cómputo de nómina.</p>
                </article>
            </div>
        </section>

        <section data-dashboard-section="payroll-operations" class="rounded-3xl border border-border bg-surface p-6 shadow-sm lg:p-8" aria-labelledby="payroll-overview-heading">
            <div class="flex flex-col gap-4 border-b border-border pb-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-dashboard-accent-strong">Estado operativo</p>
                    <h2 id="payroll-overview-heading" class="mt-1 text-2xl font-bold text-text">Resumen operativo de nómina</h2>
                    @if (! empty($payrollOverview))
                        <p class="mt-1 text-sm text-text-muted">Empresa activa: <span class="font-semibold text-text">{{ $payrollOverview['company_name'] }}</span></p>
                    @endif
                </div>
                @if (! empty($payrollOverview))
                    @can('viewAny', App\Models\PayPeriod::class)
                        <x-ui.button :href="route('nomina.index')" variant="secondary">Ver períodos de nómina</x-ui.button>
                    @endcan
                @endif
            </div>

            <div class="pt-6">
                @if (empty($payrollOverview))
                    <x-ui.empty-state title="Seleccioná una empresa para consultar sus períodos de nómina.">
                        Este resumen nunca combina empresas. Usá el selector de empresa de la barra superior.
                    </x-ui.empty-state>
                @elseif ($payrollOverview['total'] === 0)
                    @if ($payrollOverview['has_periods'])
                        <x-ui.empty-state title="No hay períodos que coincidan con el rango seleccionado.">Ajustá las fechas Desde y Hasta para ampliar la consulta.</x-ui.empty-state>
                    @else
                        <x-ui.empty-state title="Todavía no hay períodos de nómina para esta empresa.">Abrí la sección de nómina para registrar o consultar períodos.</x-ui.empty-state>
                    @endif
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ([
                            ['label' => 'Total', 'value' => $payrollOverview['total'], 'color' => 'border-brand/30 bg-brand-subtle text-brand-strong'],
                            ['label' => 'En preparación', 'value' => $payrollOverview['preparation'], 'color' => 'border-warning/30 bg-warning-subtle text-warning-strong'],
                            ['label' => 'Procesando', 'value' => $payrollOverview['processing'], 'color' => 'border-dashboard-info/30 bg-dashboard-info-subtle text-dashboard-info-strong'],
                            ['label' => 'Completadas', 'value' => $payrollOverview['completed'], 'color' => 'border-success/30 bg-success-subtle text-success-strong'],
                            ['label' => 'Validación con errores', 'value' => $payrollOverview['validation_failed'], 'color' => 'border-danger/30 bg-danger-subtle text-danger-strong'],
                            ['label' => 'Canceladas', 'value' => $payrollOverview['cancelled'], 'color' => 'border-border bg-surface-muted text-text-muted'],
                        ] as $status)
                            <article class="rounded-2xl border p-5 {{ $status['color'] }}">
                                <p class="text-sm font-semibold">{{ $status['label'] }}</p>
                                <p class="mt-2 text-3xl font-black text-text">{{ $status['value'] }}</p>
                            </article>
                        @endforeach
                    </div>

                    @if ($payrollOverview['unknown'] > 0)
                        <x-ui.alert class="mt-4" title="Otros estados registrados"><span class="font-semibold">{{ $payrollOverview['unknown'] }}</span> períodos usan un estado no agrupado.</x-ui.alert>
                    @endif
                @endif
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <section data-dashboard-section="payroll-trends" class="min-w-0 rounded-3xl border border-border bg-surface p-6 shadow-sm lg:col-span-2" aria-labelledby="payroll-trends-heading">
                <div class="border-b border-border pb-5">
                    <p class="text-xs font-bold uppercase tracking-wider text-brand">Métricas cuantitativas</p>
                    <h2 id="payroll-trends-heading" class="mt-1 text-xl font-bold text-text">Tendencia mensual de nómina</h2>
                    <p class="mt-1 text-sm text-text-muted">Resultados y horas de la empresa activa dentro del rango actual de fechas de resultados.</p>
                </div>

                <div class="pt-6">
                    @if (is_null($payrollTrends))
                        <x-ui.empty-state title="Seleccioná una empresa activa para consultar su tendencia mensual de nómina.">La tendencia se calcula solamente para la empresa seleccionada.</x-ui.empty-state>
                    @elseif (empty($payrollTrends))
                        <x-ui.empty-state title="No hay resultados de nómina para la empresa activa en el rango de fechas actual.">Ajustá el rango para consultar otros resultados.</x-ui.empty-state>
                    @else
                        <ol class="space-y-4">
                            @foreach ($payrollTrends as $trend)
                                <li class="min-w-0 rounded-2xl border border-border bg-surface-muted p-5">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <time datetime="{{ $trend['month'] }}" class="font-bold text-text">{{ $trend['label'] }}</time>
                                        <span class="rounded-full bg-brand-subtle px-3 py-1 text-xs font-bold text-brand-strong">{{ $trend['entries'] }} registros</span>
                                    </div>
                                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-surface" aria-hidden="true"><div class="h-full rounded-full bg-gradient-to-r from-dashboard-accent via-brand to-dashboard-info" @style(['width: '.$trend['bar_width'].'%'])></div></div>
                                    <dl class="mt-4 grid min-w-0 grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                                        <div class="rounded-xl bg-surface p-3"><dt class="text-text-muted">Registros de resultado</dt><dd class="mt-1 break-words text-lg font-black text-text">{{ $trend['entries'] }}</dd></div>
                                        <div class="rounded-xl bg-surface p-3"><dt class="text-text-muted">Horas ordinarias</dt><dd class="mt-1 break-words text-lg font-black text-text">{{ number_format($trend['ordinary_hours'], 2, '.', '') }}</dd></div>
                                        <div class="rounded-xl bg-surface p-3"><dt class="text-text-muted">Horas extras</dt><dd class="mt-1 break-words text-lg font-black text-text">{{ number_format($trend['extra_hours'], 2, '.', '') }}</dd></div>
                                    </dl>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </section>

            @can('audit.view')
                <section class="rounded-3xl border border-border bg-surface p-6 shadow-sm" aria-labelledby="audit-handoff-heading">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-dashboard-accent-subtle text-dashboard-accent-strong" aria-hidden="true"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z" /></svg></span>
                    <h2 id="audit-handoff-heading" class="mt-5 text-lg font-bold text-text">Historial de actividad</h2>
                    <p class="mt-2 text-sm leading-6 text-text-muted">Consultá los eventos detallados en la sección Auditoría.</p>
                    <div class="mt-6"><x-ui.button :href="route('auditoria.index')">Ver historial en Auditoría</x-ui.button></div>
                </section>
            @endcan
        </div>
    </div>
</div>
