<div class="relative isolate max-w-7xl mx-auto py-8 px-4">
    <x-ui.loading-overlay target="approve" message="Validando y aprobando la nómina…" />

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Procesar nómina</h1>
        <div class="flex gap-2">
            <a href="{{ route('nomina.revisar', ['payPeriod' => $payPeriod]) }}" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                Volver
            </a>
            @if ($isCancelled)
                <span class="px-4 py-2 bg-red-100 text-red-800 rounded">
                    Nómina cancelada
                </span>
            @else
                @if ($canApprove)
                    <x-ui.loading-button wire:click="approve" target="approve" loading-label="Aprobando…" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        Aprobar nómina
                    </x-ui.loading-button>
                @endif
                @if ($canExport)
                    <a href="{{ route('nomina.excel', ['payPeriod' => $payPeriod]) }}" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                        Generar Excel
                    </a>
                @endif
            @endif
        </div>
    </div>

    @if ($isCancelled)
        <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded">
            Nómina cancelada, no editable.
        </div>
    @endif

    @if ($locked && ! $isCancelled)
        <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded">
            Esta nómina está aprobada/exportada. Los registros no pueden modificarse directamente.
        </div>
    @endif

    <div class="mb-6 grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Empleados</div>
            <div class="text-xl font-bold">{{ $summary['total_employees'] }}</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Días</div>
            <div class="text-xl font-bold">{{ $summary['total_days'] }}</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Horas ordinarias</div>
            <div class="text-xl font-bold">{{ number_format($summary['ordinary_minutes'] / 60, 2) }}</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Horas extras</div>
            <div class="text-xl font-bold">
                {{ number_format(($summary['extra_25_minutes'] + $summary['extra_50_minutes'] + $summary['extra_75_minutes'] + $summary['extra_100_minutes']) / 60, 2) }}
            </div>
        </div>
    </div>

    <div class="mb-4 flex gap-4">
        <label class="block text-sm font-medium text-gray-700">Empleado
            <input wire:model.live="employee_id" type="number" min="1" class="mt-1 block rounded border-gray-300 shadow-sm" />
        </label>
        <label class="block text-sm font-medium text-gray-700">Estado
            <select wire:model.live="absence" class="mt-1 block rounded border-gray-300 shadow-sm">
                <option value="">Todos</option><option value="worked">Con jornada</option><option value="absence">Ausencias</option>
            </select>
        </label>
    </div>

    <div class="overflow-x-auto bg-white rounded shadow">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Nombre</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Entrada</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Salida</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cantidad Horas</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ordinarias</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ext 25%</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ext 50%</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ext 75%</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ext 100%</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($results as $result)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->employee_external_id }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->employee_name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->date->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->entry_at?->format('d/m/Y h:i A') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->exit_at?->format('d/m/Y h:i A') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->worked_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->ordinary_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->extra_25_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->extra_50_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->extra_75_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->extra_100_minutes / 60, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            @if ($result->is_absence)
                                @if ($result->is_justified)
                                    <span class="text-purple-600">Justificada</span>
                                @elseif ($result->unjustified)
                                    <span class="text-red-600">Ausencia</span>
                                @else
                                    <span class="text-orange-600">Falta marca</span>
                                @endif
                            @else
                                <span class="text-green-600">Normal</span>
                            @endif
                        </td>
                        <td class="px-4 py-2"><button wire:click="showEvidence({{ $result->id }})" class="text-indigo-700 underline">Evidencia</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($evidence)
        <section class="mt-4 rounded bg-white p-4 shadow"><h2 class="font-bold">Evidencia congelada</h2>
            <pre class="mt-2 overflow-auto text-xs">{{ json_encode($evidence->day_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>
    @endif

    <div class="mt-4">
        {{ $results->links() }}
    </div>
</div>
