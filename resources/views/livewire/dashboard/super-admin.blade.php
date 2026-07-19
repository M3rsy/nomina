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
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4 mb-6">
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
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Nóminas procesadas</p>
            <p class="text-2xl font-bold">{{ $processedPayrolls }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Nóminas pendientes</p>
            <p class="text-2xl font-bold">{{ $pendingPayrolls }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-600">Nóminas con errores</p>
            <p class="text-2xl font-bold">{{ $errorPayrolls }}</p>
        </div>
    </div>

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
