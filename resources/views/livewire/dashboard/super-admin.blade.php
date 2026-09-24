<div class="min-h-screen bg-surface-muted">
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-ui.page-header
            title="Panel super administrador"
            description="Vista consolidada de operación, salud de nómina y tendencias por período."
        />

        <x-ui.card aria-labelledby="period-filter-heading">
            <x-slot:header>
                <div>
                    <h2 id="period-filter-heading" class="text-lg font-bold text-text">Rango de análisis</h2>
                    <p class="mt-1 text-sm text-text-muted">Las tarjetas de organización muestran el estado actual. Los períodos de nómina usan inclusión completa y límites inclusivos. La tendencia mensual usa la fecha de cada resultado con límites inclusivos.</p>
                </div>
            </x-slot:header>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input id="from" label="Desde" type="date" wire:model.live="from" />
                <x-ui.input id="to" label="Hasta" type="date" wire:model.live="to" />
            </div>
        </x-ui.card>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicadores de organización">
            <x-ui.stat-card label="Empresas activas" :value="$activeCompanies" tone="success">Con acceso productivo en el alcance actual.</x-ui.stat-card>
            <x-ui.stat-card label="Empresas inactivas" :value="$inactiveCompanies" tone="danger">Sin acceso operativo en este momento.</x-ui.stat-card>
            <x-ui.stat-card label="Usuarios activos" :value="$activeUsers">Con credenciales vigentes.</x-ui.stat-card>
            <x-ui.stat-card label="Empleados activos" :value="$activeEmployees" tone="neutral">Listos para cómputo de nómina.</x-ui.stat-card>
        </section>

        <x-ui.card aria-labelledby="payroll-overview-heading">
            <x-slot:header>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 id="payroll-overview-heading" class="text-xl font-bold text-text">Resumen operativo de nómina</h2>
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
            </x-slot:header>

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
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <x-ui.stat-card label="Total" :value="$payrollOverview['total']" tone="neutral" />
                    <x-ui.stat-card label="En preparación" :value="$payrollOverview['preparation']" tone="warning" />
                    <x-ui.stat-card label="Procesando" :value="$payrollOverview['processing']" />
                    <x-ui.stat-card label="Completadas" :value="$payrollOverview['completed']" tone="success" />
                    <x-ui.stat-card label="Validación con errores" :value="$payrollOverview['validation_failed']" tone="danger" />
                    <x-ui.stat-card label="Canceladas" :value="$payrollOverview['cancelled']" tone="neutral" />
                </div>

                @if ($payrollOverview['unknown'] > 0)
                    <x-ui.alert class="mt-4" title="Otros estados registrados">
                        <span class="font-semibold">{{ $payrollOverview['unknown'] }}</span> períodos usan un estado no agrupado.
                    </x-ui.alert>
                @endif
            @endif
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            @can('audit.view')
                <x-ui.card class="lg:col-span-2" aria-labelledby="audit-handoff-heading">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 id="audit-handoff-heading" class="text-lg font-bold text-text">Historial de actividad</h2>
                            <p class="mt-1 text-sm text-text-muted">Consultá los eventos detallados en la sección Auditoría.</p>
                        </div>
                        <x-ui.button :href="route('auditoria.index')">Ver historial en Auditoría</x-ui.button>
                    </div>
                </x-ui.card>
            @endcan

            <x-ui.card class="min-w-0" aria-labelledby="payroll-trends-heading">
                <x-slot:header>
                    <div>
                        <h2 id="payroll-trends-heading" class="text-lg font-bold text-text">Tendencia mensual de nómina</h2>
                        <p class="mt-1 text-sm text-text-muted">Muestra registros de resultados y horas de la empresa activa dentro del rango actual de fechas de resultados.</p>
                    </div>
                </x-slot:header>

                @if (is_null($payrollTrends))
                    <x-ui.empty-state title="Seleccioná una empresa activa para consultar su tendencia mensual de nómina.">
                        La tendencia se calcula solamente para la empresa seleccionada.
                    </x-ui.empty-state>
                @elseif (empty($payrollTrends))
                    <x-ui.empty-state title="No hay resultados de nómina para la empresa activa en el rango de fechas actual.">
                        Ajustá el rango para consultar otros resultados.
                    </x-ui.empty-state>
                @else
                    <ol class="space-y-3">
                        @foreach ($payrollTrends as $trend)
                            <li class="min-w-0 rounded-2xl border border-border bg-surface p-4">
                                <time datetime="{{ $trend['month'] }}" class="font-bold text-text">{{ $trend['label'] }}</time>
                                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true">
                                    <div class="h-full rounded-full bg-gradient-to-r from-brand to-success" @style(['width: '.$trend['bar_width'].'%'])></div>
                                </div>
                                <dl class="mt-3 grid min-w-0 grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                                    <div class="min-w-0">
                                        <dt class="text-text-muted">Registros de resultado</dt>
                                        <dd class="mt-1 break-words font-bold text-text">{{ $trend['entries'] }}</dd>
                                    </div>
                                    <div class="min-w-0">
                                        <dt class="text-text-muted">Horas ordinarias</dt>
                                        <dd class="mt-1 break-words font-bold text-text">{{ number_format($trend['ordinary_hours'], 2, '.', '') }}</dd>
                                    </div>
                                    <div class="min-w-0">
                                        <dt class="text-text-muted">Horas extras</dt>
                                        <dd class="mt-1 break-words font-bold text-text">{{ number_format($trend['extra_hours'], 2, '.', '') }}</dd>
                                    </div>
                                </dl>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
