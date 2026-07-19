<div class="max-w-7xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">
        @if ($company)
            Panel de {{ $company->name }}
        @else
            Panel de empresa
        @endif
    </h1>

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

    @if (! $company)
        <div class="bg-yellow-50 text-yellow-700 p-4 rounded">
            No hay una empresa activa seleccionada.
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <p class="text-sm text-gray-600">Empleados activos</p>
                <p class="text-2xl font-bold">{{ $activeEmployees }}</p>
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

        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <h2 id="payroll-periods-heading" class="text-lg font-semibold mb-4">Nóminas por período</h2>
            @if (count($payPeriods) === 0)
                <p class="text-gray-500">No hay períodos de nómina.</p>
            @else
                <div role="region" aria-labelledby="payroll-periods-heading" tabindex="0" class="overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                    <table class="w-full">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-2 text-left">Período</th>
                                <th class="px-4 py-2 text-left">Estado</th>
                                <th class="px-4 py-2 text-left">Registros</th>
                                <th class="px-4 py-2 text-left">Horas ordinarias</th>
                                <th class="px-4 py-2 text-left">Horas extras</th>
                                <th class="px-4 py-2 text-left">Horas trabajadas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payPeriods as $period)
                                <tr class="border-t">
                                    <td class="px-4 py-2">{{ $period['name'] }} ({{ $period['start_date']->format('Y-m-d') }} - {{ $period['end_date']->format('Y-m-d') }})</td>
                                    <td class="px-4 py-2">{{ $period['status'] }}</td>
                                    <td class="px-4 py-2">{{ $period['results_count'] }}</td>
                                    <td class="px-4 py-2">{{ number_format($period['ordinary_hours'], 2) }}</td>
                                    <td class="px-4 py-2">{{ number_format($period['extra_hours'], 2) }}</td>
                                    <td class="px-4 py-2">{{ number_format($period['worked_hours'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <h2 class="text-lg font-semibold mb-4">Archivos recientes</h2>
                @if (count($recentFiles) === 0)
                    <p class="text-gray-500">No hay archivos recientes.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($recentFiles as $file)
                            <li class="border-b pb-2">
                                <p class="font-medium">{{ $file->original_name }}</p>
                                <p class="text-sm text-gray-600">{{ $file->created_at->format('Y-m-d H:i') }} — {{ $file->status }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="bg-white rounded-lg shadow p-4">
                <h2 class="text-lg font-semibold mb-4">Actividad reciente</h2>
                @if (empty($recentActivity))
                    <p class="text-gray-500">No hay actividad reciente.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($recentActivity as $item)
                            <li class="border-b pb-2">
                                <p class="font-medium">{{ $item['type_label'] }}</p>
                                <p class="text-sm text-gray-600">{{ $item['description'] }}</p>
                                <p class="text-xs text-gray-500">{{ $item['created_at']->format('Y-m-d H:i') }} — {{ $item['user_email'] ?? 'N/A' }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</div>
