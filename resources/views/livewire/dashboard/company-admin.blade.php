<div class="min-h-screen bg-surface-muted">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-ui.page-header
            :title="$company ? 'Panel de '.$company->name : 'Panel de empresa'"
            description="Seguimiento operativo de empleados, períodos, archivos y actividad reciente."
        >
            @if ($company)
                <x-slot:actions>
                    @can('viewAny', App\Models\PayPeriod::class)
                        <x-ui.button :href="route('nomina.index')" variant="secondary">Ver nómina</x-ui.button>
                    @endcan
                    @can('files.upload')
                        <x-ui.button :href="route('archivos.upload')">Subir archivo</x-ui.button>
                    @endcan
                </x-slot:actions>
            @endif
        </x-ui.page-header>

        <x-ui.card aria-labelledby="date-filter-heading">
            <x-slot:header>
                <div>
                    <h2 id="date-filter-heading" class="text-lg font-bold text-text">Rango de análisis</h2>
                    <p class="mt-1 text-sm text-text-muted">Los períodos y la actividad se actualizan con este rango. Los empleados activos y archivos recientes muestran el estado actual.</p>
                </div>
            </x-slot:header>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input id="from" label="Desde" type="date" wire:model.live="from" />
                <x-ui.input id="to" label="Hasta" type="date" wire:model.live="to" />
            </div>
        </x-ui.card>

        @if (! $company)
            <x-ui.alert variant="warning" title="No hay una empresa activa seleccionada">
                Seleccioná una empresa para consultar sus indicadores y actividad.
            </x-ui.alert>
        @else
            <section aria-label="Indicadores de nómina" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.stat-card label="Empleados activos" :value="$activeEmployees" tone="success">
                    Plantel activo de la empresa.
                </x-ui.stat-card>
                <x-ui.stat-card label="Nóminas pendientes" :value="$pendingPayrolls" tone="warning">
                    Períodos del rango que requieren seguimiento.
                </x-ui.stat-card>
                <x-ui.stat-card label="Nóminas con errores" :value="$errorPayrolls" :tone="$errorPayrolls > 0 ? 'danger' : 'neutral'">
                    Períodos con validaciones pendientes de corrección.
                </x-ui.stat-card>
            </section>

                @canany(['pay_periods.view', 'files.upload', 'employees.view'])
                <x-ui.card aria-labelledby="quick-actions-heading">
                    <x-slot:header>
                        <h2 id="quick-actions-heading" class="text-lg font-bold text-text">Acciones rápidas</h2>
                    </x-slot:header>
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
                </x-ui.card>
            @endcanany

            <x-ui.card aria-labelledby="payroll-periods-heading">
                <x-slot:header>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 id="payroll-periods-heading" class="text-lg font-bold text-text">Nóminas por período</h2>
                            <p class="mt-1 text-sm text-text-muted">Estado, registros y horas consolidadas del rango seleccionado.</p>
                        </div>
                        <x-ui.badge>{{ count($payPeriods) }} períodos</x-ui.badge>
                    </div>
                </x-slot:header>

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
                                    <th class="px-4 py-3">Período</th>
                                    <th class="px-4 py-3">Estado</th>
                                    <th class="px-4 py-3 text-right">Registros</th>
                                    <th class="px-4 py-3 text-right">Horas ordinarias</th>
                                    <th class="px-4 py-3 text-right">Horas extras</th>
                                    <th class="px-4 py-3 text-right">Horas trabajadas</th>
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
            </x-ui.card>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <x-ui.card aria-labelledby="recent-files-heading">
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-3">
                            <h2 id="recent-files-heading" class="text-lg font-bold text-text">Archivos recientes</h2>
                            @can('files.view')
                                <x-ui.button :href="route('archivos.index')" variant="secondary">Ver archivos</x-ui.button>
                            @endcan
                        </div>
                    </x-slot:header>
                    @if (count($recentFiles) === 0)
                        <x-ui.empty-state title="No hay archivos recientes para mostrar.">
                            Los archivos cargados aparecerán acá con su estado actual.
                        </x-ui.empty-state>
                    @else
                        <ul class="divide-y divide-border">
                            @foreach ($recentFiles as $file)
                                <li class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-text">{{ $file->original_name }}</p>
                                        <time class="text-sm text-text-muted" datetime="{{ $file->created_at->toIso8601String() }}">{{ $file->created_at->format('Y-m-d H:i') }}</time>
                                    </div>
                                    <x-ui.badge>{{ $file->status }}</x-ui.badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>

                <x-ui.card aria-labelledby="recent-activity-heading">
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-3">
                            <h2 id="recent-activity-heading" class="text-lg font-bold text-text">Actividad reciente</h2>
                            @can('audit.view')
                                <x-ui.button :href="route('auditoria.index')" variant="secondary">Ver auditoría</x-ui.button>
                            @endcan
                        </div>
                    </x-slot:header>
                    @if (empty($recentActivity))
                        <x-ui.empty-state title="No hay actividad en el rango seleccionado.">
                            Los eventos registrados dentro de las fechas elegidas aparecerán acá.
                        </x-ui.empty-state>
                    @else
                        <ol class="divide-y divide-border">
                            @foreach ($recentActivity as $item)
                                <li class="py-4 first:pt-0 last:pb-0">
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
                </x-ui.card>
            </div>
        @endif
    </div>
</div>
