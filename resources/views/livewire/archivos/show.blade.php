<div class="max-w-7xl mx-auto py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Detalle del archivo</h1>
        <a href="/archivos" class="text-indigo-600 hover:underline">Volver</a>
    </div>

    <div class="bg-white p-6 rounded-lg shadow mb-6">
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><strong>Nombre original:</strong> {{ $uploadedFile->original_name }}</div>
            <div><strong>Estado:</strong> {{ $uploadedFile->status }}</div>
            <div><strong>Período:</strong> {{ $uploadedFile->payPeriod?->name ?? '-' }}</div>
            <div><strong>Total registros:</strong> {{ $summary['total'] ?? 0 }}</div>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow mb-6">
        <h2 class="text-lg font-semibold mb-4">Resumen</h2>
        <div class="grid grid-cols-3 gap-4 text-sm">
            <div>Válidos: <strong>{{ $summary['valid'] ?? 0 }}</strong></div>
            <div>Duplicados: <strong>{{ $summary['duplicate'] ?? 0 }}</strong></div>
            <div>Fuera de período: <strong>{{ $summary['out_of_period'] ?? 0 }}</strong></div>
            <div>Empleados desconocidos: <strong>{{ $summary['unknown_employee'] ?? 0 }}</strong></div>
            <div>Filas inválidas: <strong>{{ $summary['invalid_row'] ?? 0 }}</strong></div>
        </div>
    </div>

    <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold">Registros</h2>
        <a href="/archivos/{{ $uploadedFile->id }}/reporte" class="bg-gray-200 text-gray-800 px-4 py-2 rounded hover:bg-gray-300">Descargar reporte</a>
    </div>

    <table class="w-full bg-white rounded-lg shadow">
        <thead class="bg-gray-100">
            <tr>
                <th class="px-4 py-2 text-left">#</th>
                <th class="px-4 py-2 text-left">Empleado</th>
                <th class="px-4 py-2 text-left">Fecha y hora</th>
                <th class="px-4 py-2 text-left">Origen</th>
                <th class="px-4 py-2 text-left">Estado</th>
                <th class="px-4 py-2 text-left">Notas</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $record->row_number }}</td>
                    <td class="px-4 py-2">{{ $record->employee_external_id }}</td>
                    <td class="px-4 py-2">{{ $record->event_at->format('d/m/Y H:i:s') }}</td>
                    <td class="px-4 py-2">{{ $record->source }}</td>
                    <td class="px-4 py-2">{{ $record->status }}</td>
                    <td class="px-4 py-2">{{ $record->notes ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-4 text-center text-gray-500">No se encontraron registros.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">
        {{ $records->links() }}
    </div>
</div>
