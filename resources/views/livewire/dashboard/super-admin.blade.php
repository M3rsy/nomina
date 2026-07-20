<div class="max-w-7xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Panel super administrador</h1>

    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap gap-4 items-end">
            <div>
                <label for="from" class="block text-sm font-medium text-gray-700">Desde</label>
                <input id="from" type="date" wire:model.live="from" class="mt-1 block rounded border-gray-300 shadow-sm">
            </div>

            <div>
                <label for="to" class="block text-sm font-medium text-gray-700">Hasta</label>
                <input id="to" type="date" wire:model.live="to" class="mt-1 block rounded border-gray-300 shadow-sm">
            </div>
        </div>
        <p class="mt-3 text-xs leading-5 text-slate-500">Las tarjetas de organización muestran el estado actual. Los períodos de nómina usan inclusión completa y límites inclusivos. Las estadísticas mensuales conservan su comportamiento actual de fechas.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Empresas activas</p>
            <p class="text-2xl font-bold">{{ $activeCompanies }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Empresas inactivas</p>
            <p class="text-2xl font-bold">{{ $inactiveCompanies }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Usuarios activos</p>
            <p class="text-2xl font-bold">{{ $activeUsers }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Empleados activos</p>
            <p class="text-2xl font-bold">{{ $activeEmployees }}</p>
        </div>
    </div>

    <section class="mb-6" aria-labelledby="payroll-overview-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 id="payroll-overview-heading" class="text-lg font-semibold text-slate-900">Resumen operativo de nómina</h2>
                @if (! empty($payrollOverview))
                    <p class="mt-1 text-sm text-slate-600">Empresa activa: <span class="font-medium text-slate-800">{{ $payrollOverview['company_name'] }}</span></p>
                @endif
            </div>

            @if (! empty($payrollOverview))
                @can('viewAny', App\Models\PayPeriod::class)
                    <a href="{{ route('nomina.index') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-indigo-300 hover:text-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Ver períodos de nómina
                    </a>
                @endcan
            @endif
        </div>

        @if (empty($payrollOverview))
            <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="font-semibold text-slate-900">Seleccioná una empresa para consultar sus períodos de nómina.</p>
                <p class="mt-1 text-sm text-slate-600">Este resumen nunca combina empresas. Usá el selector de empresa de la barra superior.</p>
            </div>
        @elseif ($payrollOverview['total'] === 0)
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5">
                @if ($payrollOverview['has_periods'])
                    <p class="font-semibold text-slate-900">No hay períodos que coincidan con el rango seleccionado.</p>
                    <p class="mt-1 text-sm text-slate-600">Ajustá las fechas Desde y Hasta para ampliar la consulta.</p>
                @else
                    <p class="font-semibold text-slate-900">Todavía no hay períodos de nómina para esta empresa.</p>
                    <p class="mt-1 text-sm text-slate-600">Abrí la sección de nómina para registrar o consultar períodos.</p>
                @endif
            </div>
        @else
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div class="min-h-32 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">Total</span>
                    <p class="mt-4 text-3xl font-bold text-slate-950">{{ $payrollOverview['total'] }}</p>
                </div>
                <div class="min-h-32 rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
                    <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">En preparación</span>
                    <p class="mt-4 text-3xl font-bold text-amber-950">{{ $payrollOverview['preparation'] }}</p>
                </div>
                <div class="min-h-32 rounded-2xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm">
                    <span class="inline-flex rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800">Procesando</span>
                    <p class="mt-4 text-3xl font-bold text-blue-950">{{ $payrollOverview['processing'] }}</p>
                </div>
                <div class="min-h-32 rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
                    <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Completadas</span>
                    <p class="mt-4 text-3xl font-bold text-emerald-950">{{ $payrollOverview['completed'] }}</p>
                </div>
                <div class="min-h-32 rounded-2xl border border-rose-200 bg-rose-50/50 p-4 shadow-sm">
                    <span class="inline-flex rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-800">Validación con errores</span>
                    <p class="mt-4 text-3xl font-bold text-rose-950">{{ $payrollOverview['validation_failed'] }}</p>
                </div>
                <div class="min-h-32 rounded-2xl border border-slate-300 bg-slate-50 p-4 shadow-sm">
                    <span class="inline-flex rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700">Canceladas</span>
                    <p class="mt-4 text-3xl font-bold text-slate-950">{{ $payrollOverview['cancelled'] }}</p>
                </div>
            </div>

            @if ($payrollOverview['unknown'] > 0)
                <div class="mt-3 flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                    <span>Otros estados registrados</span>
                    <span class="inline-flex min-w-8 justify-center rounded-full bg-slate-200 px-2.5 py-1 font-semibold text-slate-800">{{ $payrollOverview['unknown'] }}</span>
                </div>
            @endif
        @endif
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @can('audit.view')
            <div class="lg:col-span-2 bg-white rounded-lg border border-slate-200 shadow p-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Historial de actividad</h2>
                        <p class="mt-1 text-sm text-slate-600">Consultá los eventos detallados en la sección Auditoría.</p>
                    </div>
                    <a href="{{ route('auditoria.index') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Ver historial en Auditoría
                    </a>
                </div>
            </div>
        @endcan

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="text-lg font-semibold mb-4">Estadísticas generales</h2>
            @if (empty($generalStats))
                <p class="text-gray-500">No hay datos.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($generalStats as $stat)
                        <li class="border-b pb-2">
                            <p class="font-medium">{{ $stat['month'] }} — {{ $stat['company_name'] }}</p>
                            <p class="text-sm text-gray-600">Registros: {{ $stat['entries'] }}</p>
                            <p class="text-sm text-gray-600">Horas ordinarias: {{ number_format($stat['ordinary_hours'], 2) }}</p>
                            <p class="text-sm text-gray-600">Horas extras: {{ number_format($stat['extra_hours'], 2) }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
