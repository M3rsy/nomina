<div class="max-w-7xl mx-auto py-8 px-4">
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
                    <button wire:click="openApproveConfirm" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        Aprobar
                    </button>
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
            <div class="text-sm text-gray-500">Registros</div>
            <div class="text-xl font-bold">{{ $summary['total_records'] }}</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Horas ordinarias</div>
            <div class="text-xl font-bold">{{ number_format($summary['ordinary_hours'], 2) }}</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
            <div class="text-sm text-gray-500">Horas extras</div>
            <div class="text-xl font-bold">
                {{ $summary['extra_25_hours'] + $summary['extra_50_hours'] + $summary['extra_75_hours'] + $summary['extra_100_hours'] }}
            </div>
        </div>
    </div>

    <div class="mb-4">
        <label for="employee_id" class="block text-sm font-medium text-gray-700">Filtrar por empleado</label>
        <select wire:model.live="employee_id" id="employee_id" class="mt-1 block w-full md:w-1/3 rounded border-gray-300 shadow-sm">
            <option value="">Todos</option>
            @foreach ($employees as $employee)
                <option value="{{ $employee->id }}">{{ $employee->full_name }} ({{ $employee->external_id }})</option>
            @endforeach
        </select>
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
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->employee?->external_id }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->employee?->full_name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->date->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->entry_at?->format('d/m/Y h:i A') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->exit_at?->format('d/m/Y h:i A') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->worked_hours, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($result->ordinary_hours, 2) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->extra_25_hours }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->extra_50_hours }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->extra_75_hours }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $result->extra_100_hours }}</td>
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
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $results->links() }}
    </div>

    @if ($showApproveConfirm)
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50" wire:click.self="closeApproveConfirm">
            <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-6">
                <h2 class="text-lg font-bold mb-4">Confirmar aprobación</h2>
                <p class="mb-6 text-gray-700">
                    Al aprobar la nómina no podrá modificar los registros directamente. Cualquier corrección posterior requerirá un proceso controlado y auditado.
                </p>
                <div class="flex justify-end gap-2">
                    <button wire:click="closeApproveConfirm" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                        Cancelar
                    </button>
                    <button wire:click="approve" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        Confirmar aprobación
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
