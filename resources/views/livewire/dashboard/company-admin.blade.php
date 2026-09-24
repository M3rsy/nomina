<div class="min-h-screen bg-surface-muted">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section data-dashboard-section="hero" class="relative overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="company-dashboard-heading">
            <div class="h-1.5 bg-gradient-to-r from-dashboard-accent via-brand to-dashboard-info" aria-hidden="true"></div>
            <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] lg:p-8">
                <div class="min-w-0">
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center rounded-full bg-dashboard-accent-subtle px-3 py-1 text-xs font-bold uppercase tracking-wider text-dashboard-accent-strong">Centro operativo</span>
                        @if ($company)
                            <span class="inline-flex items-center rounded-full bg-success-subtle px-3 py-1 text-xs font-semibold text-success-strong">Empresa activa</span>
                        @endif
                    </div>
                    <h1 id="company-dashboard-heading" class="mt-4 text-3xl font-black tracking-tight text-text sm:text-4xl">{{ $company ? 'Panel de '.$company->name : 'Panel de empresa' }}</h1>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-text-muted">Seguimiento operativo de empleados, períodos, archivos y actividad reciente.</p>

                    <div class="mt-6 rounded-2xl border border-dashboard-info/30 bg-dashboard-info-subtle p-4">
                        <p class="text-xs font-bold uppercase tracking-wider text-dashboard-info-strong">Alcance de empresa</p>
                        @if ($company)
                            <p class="mt-1 text-sm text-text">Todos los indicadores y eventos de este panel corresponden únicamente a <span class="font-bold">{{ $company->name }}</span>.</p>
                        @else
                            <p class="mt-1 text-sm text-text">Seleccioná una empresa activa para consultar indicadores y actividad sin combinar datos entre compañías.</p>
                        @endif
                    </div>

                    @if ($company)
                        <div class="mt-6 flex flex-wrap gap-3">
                            @can('viewAny', App\Models\PayPeriod::class)
                                <x-ui.button :href="route('nomina.index')">Ver nómina</x-ui.button>
                            @endcan
                            @can('files.upload')
                                <x-ui.button :href="route('archivos.upload')" variant="secondary">Subir archivo</x-ui.button>
                            @endcan
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl border border-border bg-surface-muted p-5" aria-labelledby="date-filter-heading">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-subtle text-brand" aria-hidden="true">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z" /></svg>
                        </span>
                        <div>
                            <h2 id="date-filter-heading" class="font-bold text-text">Rango de análisis</h2>
                            <p class="mt-1 text-sm text-text-muted">Períodos y actividad dentro de las fechas elegidas.</p>
                        </div>
                    </div>
                    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        <x-ui.input id="from" label="Desde" type="date" wire:model.live="from" />
                        <x-ui.input id="to" label="Hasta" type="date" wire:model.live="to" />
                    </div>
                    <p class="mt-4 text-xs leading-5 text-text-muted">Los períodos y la actividad se actualizan con este rango. Los empleados activos y archivos recientes muestran el estado actual.</p>
                </div>
            </div>
        </section>

        @if (! $company)
            <x-ui.alert variant="warning" title="No hay una empresa activa seleccionada">
                Seleccioná una empresa para consultar sus indicadores y actividad.
            </x-ui.alert>
        @else
            <section data-dashboard-section="company-kpis" aria-labelledby="company-kpis-heading">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-brand">Panorama de empresa</p>
                        <h2 id="company-kpis-heading" class="mt-1 text-xl font-bold text-text">Indicadores de nómina</h2>
                    </div>
                    <span class="rounded-full border border-border bg-surface px-3 py-1 text-xs font-semibold text-text-muted">Estado actual y rango seleccionado</span>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <span class="flex size-11 items-center justify-center rounded-xl bg-success-subtle text-success-strong" aria-hidden="true"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87" /></svg></span>
                            <span class="rounded-full bg-success-subtle px-2.5 py-1 text-xs font-bold text-success-strong">Activos</span>
                        </div>
                        <p class="mt-5 text-sm font-medium text-text-muted">Empleados activos</p>
                        <p class="mt-1 text-3xl font-black text-text">{{ $activeEmployees }}</p>
                        <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true"><div class="h-full w-full rounded-full bg-success"></div></div>
                        <p class="mt-3 text-xs text-text-muted">Plantel activo de la empresa.</p>
                    </article>

                    <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <span class="flex size-11 items-center justify-center rounded-xl bg-warning-subtle text-warning-strong" aria-hidden="true"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg></span>
                            <span class="rounded-full bg-warning-subtle px-2.5 py-1 text-xs font-bold text-warning-strong">Seguimiento</span>
                        </div>
                        <p class="mt-5 text-sm font-medium text-text-muted">Nóminas pendientes</p>
                        <p class="mt-1 text-3xl font-black text-text">{{ $pendingPayrolls }}</p>
                        <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true"><div class="h-full w-full rounded-full bg-warning"></div></div>
                        <p class="mt-3 text-xs text-text-muted">Períodos del rango que requieren seguimiento.</p>
                    </article>

                    <article class="rounded-2xl border border-border bg-surface p-5 shadow-sm sm:col-span-2 lg:col-span-1">
                        <div class="flex items-start justify-between gap-4">
                            <span @class(['flex size-11 items-center justify-center rounded-xl', 'bg-danger-subtle text-danger-strong' => $errorPayrolls > 0, 'bg-surface-muted text-text-muted' => $errorPayrolls === 0]) aria-hidden="true"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z" /></svg></span>
                            <span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-danger-subtle text-danger-strong' => $errorPayrolls > 0, 'bg-surface-muted text-text-muted' => $errorPayrolls === 0])>{{ $errorPayrolls > 0 ? 'Revisar' : 'Sin alertas' }}</span>
                        </div>
                        <p class="mt-5 text-sm font-medium text-text-muted">Nóminas con errores</p>
                        <p class="mt-1 text-3xl font-black text-text">{{ $errorPayrolls }}</p>
                        <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true"><div @class(['h-full w-full rounded-full', 'bg-danger' => $errorPayrolls > 0, 'bg-border' => $errorPayrolls === 0])></div></div>
                        <p class="mt-3 text-xs text-text-muted">Períodos con validaciones pendientes de corrección.</p>
                    </article>
                </div>
            </section>

            @canany(['pay_periods.view', 'files.upload', 'employees.view'])
                <section data-dashboard-section="quick-actions" class="rounded-3xl border border-border bg-surface p-6 shadow-sm" aria-labelledby="quick-actions-heading">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-dashboard-accent-strong">Atajos operativos</p>
                            <h2 id="quick-actions-heading" class="mt-1 text-xl font-bold text-text">Acciones rápidas</h2>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            @can('viewAny', App\Models\PayPeriod::class)
                                <x-ui.button :href="route('nomina.index')">Ver nómina</x-ui.button>
                            @endcan
                            @can('files.upload')
                                <x-ui.button :href="route('archivos.upload')" variant="secondary">Subir archivo</x-ui.button>
                            @endcan
                            @can('employees.view')
                                <x-ui.button :href="route('empleados.index')" variant="secondary">Ver empleados</x-ui.button>
                            @endcan
                        </div>
                    </div>
                </section>
            @endcanany

            <section data-dashboard-section="payroll-operations" class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="payroll-periods-heading">
                <div class="flex flex-col gap-4 border-b border-border p-6 sm:flex-row sm:items-end sm:justify-between lg:px-8">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-dashboard-accent-strong">Estado operativo</p>
                        <h2 id="payroll-periods-heading" class="mt-1 text-2xl font-bold text-text">Nóminas por período</h2>
                        <p class="mt-1 text-sm text-text-muted">Estado, registros y horas consolidadas del rango seleccionado.</p>
                    </div>
                    <x-ui.badge>{{ count($payPeriods) }} períodos</x-ui.badge>
                </div>

                <div class="p-6 lg:px-8">
                    @if (count($payPeriods) === 0)
                        <x-ui.empty-state title="Todavía no hay períodos de nómina en el rango seleccionado.">
                            Ajustá las fechas o ingresá a nómina para consultar los períodos disponibles.
                            @can('viewAny', App\Models\PayPeriod::class)
                                <x-slot:actions>
                                    <x-ui.button :href="route('nomina.index')" variant="secondary">Ver nómina</x-ui.button>
                                </x-slot:actions>
                            @endcan
                        </x-ui.empty-state>
                    @else
                        <div role="region" aria-labelledby="payroll-periods-heading" tabindex="0" class="overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                            <table class="w-full min-w-[760px] text-sm">
                                <thead class="bg-surface-muted text-left text-xs font-semibold uppercase tracking-wide text-text-muted">
                                    <tr>
                                        <th scope="col" class="px-4 py-3">Período</th>
                                        <th scope="col" class="px-4 py-3">Estado</th>
                                        <th scope="col" class="px-4 py-3 text-right">Registros</th>
                                        <th scope="col" class="px-4 py-3 text-right">Horas ordinarias</th>
                                        <th scope="col" class="px-4 py-3 text-right">Horas extras</th>
                                        <th scope="col" class="px-4 py-3 text-right">Horas trabajadas</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($payPeriods as $period)
                                        @php
                                            $statusVariant = match ($period['status']) {
                                                'processed', 'approved', 'exported' => 'success',
                                                'validation_failed' => 'danger',
                                                'draft', 'uploaded', 'validating', 'ready', 'processing' => 'warning',
                                                default => 'neutral',
                                            };
                                        @endphp
                                        <tr class="text-text">
                                            <td class="px-4 py-4 font-semibold">{{ $period['name'] }}<span class="mt-1 block text-xs font-normal text-text-muted">{{ $period['start_date']->format('Y-m-d') }} – {{ $period['end_date']->format('Y-m-d') }}</span></td>
                                            <td class="px-4 py-4"><x-ui.badge :variant="$statusVariant">{{ $period['status'] }}</x-ui.badge></td>
                                            <td class="px-4 py-4 text-right tabular-nums">{{ $period['results_count'] }}</td>
                                            <td class="px-4 py-4 text-right tabular-nums">{{ number_format($period['ordinary_hours'], 2) }}</td>
                                            <td class="px-4 py-4 text-right tabular-nums">{{ number_format($period['extra_hours'], 2) }}</td>
                                            <td class="px-4 py-4 text-right font-semibold tabular-nums">{{ number_format($period['worked_hours'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section data-dashboard-section="recent-files" class="rounded-3xl border border-border bg-surface p-6 shadow-sm" aria-labelledby="recent-files-heading">
                    <div class="flex items-center justify-between gap-3 border-b border-border pb-5">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-brand">Documentos</p>
                            <h2 id="recent-files-heading" class="mt-1 text-xl font-bold text-text">Archivos recientes</h2>
                        </div>
                        @can('files.view')
                            <x-ui.button :href="route('archivos.index')" variant="secondary">Ver archivos</x-ui.button>
                        @endcan
                    </div>
                    <div class="pt-6">
                        @if (count($recentFiles) === 0)
                            <x-ui.empty-state title="No hay archivos recientes para mostrar.">
                                Los archivos cargados aparecerán acá con su estado actual.
                            </x-ui.empty-state>
                        @else
                            <ul class="space-y-3">
                                @foreach ($recentFiles as $file)
                                    <li class="flex flex-col gap-3 rounded-2xl border border-border bg-surface-muted p-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold text-text">{{ $file->original_name }}</p>
                                            <time class="text-sm text-text-muted" datetime="{{ $file->created_at->toIso8601String() }}">{{ $file->created_at->format('Y-m-d H:i') }}</time>
                                        </div>
                                        <x-ui.badge>{{ $file->status }}</x-ui.badge>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>

                <section data-dashboard-section="recent-activity" class="rounded-3xl border border-border bg-surface p-6 shadow-sm" aria-labelledby="recent-activity-heading">
                    <div class="flex items-center justify-between gap-3 border-b border-border pb-5">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-dashboard-info-strong">Seguimiento</p>
                            <h2 id="recent-activity-heading" class="mt-1 text-xl font-bold text-text">Actividad reciente</h2>
                        </div>
                        @can('audit.view')
                            <x-ui.button :href="route('auditoria.index')" variant="secondary">Ver auditoría</x-ui.button>
                        @endcan
                    </div>
                    <div class="pt-6">
                        @if (empty($recentActivity))
                            <x-ui.empty-state title="No hay actividad en el rango seleccionado.">
                                Los eventos registrados dentro de las fechas elegidas aparecerán acá.
                            </x-ui.empty-state>
                        @else
                            <ol class="space-y-3">
                                @foreach ($recentActivity as $item)
                                    <li class="relative rounded-2xl border border-border bg-surface-muted p-4 pl-10">
                                        <span class="absolute left-4 top-5 size-2.5 rounded-full bg-dashboard-info" aria-hidden="true"></span>
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <p class="font-semibold text-text">{{ $item['type_label'] }}</p>
                                            <time class="text-xs text-text-muted" datetime="{{ $item['created_at']->toIso8601String() }}">{{ $item['created_at']->format('Y-m-d H:i') }}</time>
                                        </div>
                                        <p class="mt-1 text-sm text-text-muted">{{ $item['description'] }}</p>
                                        <p class="mt-1 text-xs text-text-muted">{{ $item['user_email'] ?? 'N/A' }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                </section>
            </div>
        @endif
    </div>
</div>
