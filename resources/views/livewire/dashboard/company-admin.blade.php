<div class="min-h-screen bg-surface-muted">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section data-dashboard-section="hero" class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="dashboard-heading">
            <div class="h-2 bg-gradient-to-r from-brand via-warning to-success" aria-hidden="true"></div>
            <div class="flex flex-col gap-6 px-6 py-7 sm:px-8 lg:flex-row lg:items-center lg:justify-between lg:py-8">
                <div class="max-w-3xl">
                    <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-brand/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-brand">
                        <span class="h-2 w-2 rounded-full bg-success" aria-hidden="true"></span>
                        Vista ejecutiva
                    </div>
                    <p class="text-xs font-bold uppercase tracking-wider text-brand">Alcance de empresa</p>
                    <h1 id="dashboard-heading" class="text-3xl font-black tracking-tight text-text sm:text-4xl">
                        {{ $company ? 'Panel de '.$company->name : 'Panel de empresa' }}
                    </h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-text-muted sm:text-base">Seguimiento operativo de empleados, períodos, archivos y actividad reciente.</p>
                </div>

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    @if ($company)
                        <div class="flex flex-wrap gap-3">
                            @can('viewAny', App\Models\PayPeriod::class)
                                <x-ui.button :href="route('nomina.index')" variant="secondary">Ver nómina</x-ui.button>
                            @endcan
                            @can('files.upload')
                                <x-ui.button :href="route('archivos.upload')">Subir archivo</x-ui.button>
                            @endcan
                        </div>
                    @endif
                    <div class="hidden h-20 w-20 shrink-0 items-center justify-center rounded-3xl bg-brand/10 text-brand sm:flex" aria-hidden="true">
                        <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9m5 10V5m5 14v-7m5 7V3M2 21h20" />
                        </svg>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-border bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="date-filter-heading">
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,0.9fr)] lg:items-end">
                <div>
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-brand/10 text-brand" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M3 9h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z" />
                            </svg>
                        </span>
                        <div>
                            <h2 id="date-filter-heading" class="text-lg font-extrabold text-text">Rango de análisis</h2>
                            <p class="mt-0.5 text-sm text-text-muted">Ajustá la ventana temporal de la operación de nómina.</p>
                        </div>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-text-muted">Los períodos y la actividad se actualizan con este rango. Los empleados activos y archivos recientes muestran el estado actual.</p>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <x-ui.input id="from" label="Desde" type="date" wire:model.live="from" />
                    <x-ui.input id="to" label="Hasta" type="date" wire:model.live="to" />
                </div>
            </div>
        </section>

        @if (! $company)
            <x-ui.alert variant="warning" title="No hay una empresa activa seleccionada">
                Seleccioná una empresa para consultar sus indicadores y actividad.
            </x-ui.alert>
        @else
            <section data-dashboard-section="company-kpis" aria-label="Indicadores de nómina" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-text-muted">Empleados activos</p>
                            <p class="mt-2 text-3xl font-black tracking-tight text-text">{{ $activeEmployees }}</p>
                        </div>
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-success/10 text-success" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6 10v-2a6 6 0 0 0-12 0v2m14-10 2 2 3-4" /></svg>
                        </span>
                    </div>
                    <p class="mt-4 text-xs leading-5 text-text-muted">Plantel activo de la empresa.</p>
                    <div class="mt-4 h-1 rounded-full bg-success" aria-hidden="true"></div>
                </article>

                <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-text-muted">Nóminas pendientes</p>
                            <p class="mt-2 text-3xl font-black tracking-tight text-text">{{ $pendingPayrolls }}</p>
                        </div>
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-warning/10 text-warning" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </span>
                    </div>
                    <p class="mt-4 text-xs leading-5 text-text-muted">Períodos del rango que requieren seguimiento.</p>
                    <div class="mt-4 h-1 rounded-full bg-warning" aria-hidden="true"></div>
                </article>

                <article class="rounded-3xl border border-border bg-surface p-5 shadow-sm sm:col-span-2 lg:col-span-1">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-text-muted">Nóminas con errores</p>
                            <p class="mt-2 text-3xl font-black tracking-tight text-text">{{ $errorPayrolls }}</p>
                        </div>
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl {{ $errorPayrolls > 0 ? 'bg-danger/10 text-danger' : 'bg-surface-muted text-text-muted' }}" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z" /></svg>
                        </span>
                    </div>
                    <p class="mt-4 text-xs leading-5 text-text-muted">Períodos con validaciones pendientes de corrección.</p>
                    <div class="mt-4 h-1 rounded-full {{ $errorPayrolls > 0 ? 'bg-danger' : 'bg-border' }}" aria-hidden="true"></div>
                </article>
            </section>

            @canany(['pay_periods.view', 'files.upload', 'employees.view'])
                <section data-dashboard-section="quick-actions" class="relative overflow-hidden rounded-3xl bg-text p-6 text-white shadow-sm" aria-labelledby="quick-actions-heading">
                    <div class="absolute -right-10 -top-16 h-40 w-40 rounded-full bg-brand opacity-40" aria-hidden="true"></div>
                    <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-warning">Atajos operativos</p>
                            <h2 id="quick-actions-heading" class="mt-1 text-2xl font-black">Acciones rápidas</h2>
                            <p class="mt-2 text-sm leading-6 text-white/75">Accedé a las tareas más frecuentes de la gestión diaria.</p>
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
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-5 sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">Estado operativo</p>
                        <h2 id="payroll-periods-heading" class="mt-1 text-xl font-black text-text">Nóminas por período</h2>
                        <p class="mt-1 text-sm text-text-muted">Estado, registros y horas consolidadas del rango seleccionado.</p>
                    </div>
                    <x-ui.badge>{{ count($payPeriods) }} períodos</x-ui.badge>
                </div>

                @if (count($payPeriods) === 0)
                    <div class="p-5 sm:p-6">
                        <x-ui.empty-state title="Todavía no hay períodos de nómina en el rango seleccionado.">
                            Ajustá las fechas o ingresá a nómina para consultar los períodos disponibles.
                            @can('viewAny', App\Models\PayPeriod::class)
                                <x-slot:actions>
                                    <x-ui.button :href="route('nomina.index')" variant="secondary">Ver nómina</x-ui.button>
                                </x-slot:actions>
                            @endcan
                        </x-ui.empty-state>
                    </div>
                @else
                    <div role="region" aria-labelledby="payroll-periods-heading" tabindex="0" class="overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead class="bg-surface-muted text-left text-xs font-bold uppercase tracking-wide text-text-muted">
                                <tr>
                                    <th class="px-5 py-3 sm:px-6">Período</th>
                                    <th class="px-4 py-3">Estado</th>
                                    <th class="px-4 py-3 text-right">Registros</th>
                                    <th class="px-4 py-3 text-right">Horas ordinarias</th>
                                    <th class="px-4 py-3 text-right">Horas extras</th>
                                    <th class="px-5 py-3 text-right sm:px-6">Horas trabajadas</th>
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
                                    <tr class="text-text transition-colors hover:bg-surface-muted">
                                        <td class="px-5 py-4 font-semibold sm:px-6">{{ $period['name'] }}<span class="mt-1 block text-xs font-normal text-text-muted">{{ $period['start_date']->format('Y-m-d') }} – {{ $period['end_date']->format('Y-m-d') }}</span></td>
                                        <td class="px-4 py-4"><x-ui.badge :variant="$statusVariant">{{ $period['status'] }}</x-ui.badge></td>
                                        <td class="px-4 py-4 text-right tabular-nums">{{ $period['results_count'] }}</td>
                                        <td class="px-4 py-4 text-right tabular-nums">{{ number_format($period['ordinary_hours'], 2) }}</td>
                                        <td class="px-4 py-4 text-right tabular-nums">{{ number_format($period['extra_hours'], 2) }}</td>
                                        <td class="px-5 py-4 text-right font-bold tabular-nums sm:px-6">{{ number_format($period['worked_hours'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section data-dashboard-section="recent-files" class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="recent-files-heading">
                    <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-5 sm:px-6">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">Documentación</p>
                            <h2 id="recent-files-heading" class="mt-1 text-xl font-black text-text">Archivos recientes</h2>
                        </div>
                        @can('files.view')
                            <x-ui.button :href="route('archivos.index')" variant="secondary">Ver archivos</x-ui.button>
                        @endcan
                    </div>
                    <div class="p-5 sm:p-6">
                        @if (count($recentFiles) === 0)
                            <x-ui.empty-state title="No hay archivos recientes para mostrar.">
                                Los archivos cargados aparecerán acá con su estado actual.
                            </x-ui.empty-state>
                        @else
                            <ul class="divide-y divide-border">
                                @foreach ($recentFiles as $file)
                                    <li class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand/10 text-brand" aria-hidden="true">
                                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 0 0-2 2v16h16V8m-6-6v6h6M8 13h8m-8 4h5" /></svg>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="truncate font-bold text-text">{{ $file->original_name }}</p>
                                                <time class="text-sm text-text-muted" datetime="{{ $file->created_at->toIso8601String() }}">{{ $file->created_at->format('Y-m-d H:i') }}</time>
                                            </div>
                                        </div>
                                        <x-ui.badge>{{ $file->status }}</x-ui.badge>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>

                <section data-dashboard-section="recent-activity" class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm" aria-labelledby="recent-activity-heading">
                    <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-5 sm:px-6">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">Trazabilidad</p>
                            <h2 id="recent-activity-heading" class="mt-1 text-xl font-black text-text">Actividad reciente</h2>
                        </div>
                        @can('audit.view')
                            <x-ui.button :href="route('auditoria.index')" variant="secondary">Ver auditoría</x-ui.button>
                        @endcan
                    </div>
                    <div class="p-5 sm:p-6">
                        @if (empty($recentActivity))
                            <x-ui.empty-state title="No hay actividad en el rango seleccionado.">
                                Los eventos registrados dentro de las fechas elegidas aparecerán acá.
                            </x-ui.empty-state>
                        @else
                            <ol class="relative space-y-5 before:absolute before:bottom-2 before:left-[0.3rem] before:top-2 before:w-px before:bg-border">
                                @foreach ($recentActivity as $item)
                                    <li class="relative pl-6">
                                        <span class="absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full bg-brand ring-4 ring-surface" aria-hidden="true"></span>
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <p class="font-bold text-text">{{ $item['type_label'] }}</p>
                                            <time class="text-xs text-text-muted" datetime="{{ $item['created_at']->toIso8601String() }}">{{ $item['created_at']->format('Y-m-d H:i') }}</time>
                                        </div>
                                        <p class="mt-1 text-sm leading-5 text-text-muted">{{ $item['description'] }}</p>
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
