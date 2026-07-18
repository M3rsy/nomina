<div class="max-w-7xl mx-auto py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Nómina</h1>
        @can('pay_periods.manage')
            <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700" disabled>
                Crear período
            </button>
        @endcan
    </div>

    @if (! $hasCompany)
        <div class="bg-yellow-50 text-yellow-700 p-4 rounded">
            Seleccione una empresa para ver sus períodos de nómina.
        </div>
    @else
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-2 text-left">Nombre</th>
                        <th class="px-4 py-2 text-left">Inicio</th>
                        <th class="px-4 py-2 text-left">Fin</th>
                        <th class="px-4 py-2 text-left">Estado</th>
                        <th class="px-4 py-2 text-left">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payPeriods as $payPeriod)
                        @php
                            $statusClass = match ($payPeriod->status) {
                                'ready' => 'bg-green-100 text-green-800',
                                'validating' => 'bg-blue-100 text-blue-800',
                                'approved' => 'bg-purple-100 text-purple-800',
                                'exported' => 'bg-gray-100 text-gray-800',
                                'cancelled' => 'bg-red-100 text-red-800',
                                default => 'bg-yellow-100 text-yellow-800',
                            };
                        @endphp
                        <tr class="border-t">
                            <td class="px-4 py-2">{{ $payPeriod->name ?? $payPeriod->slug }}</td>
                            <td class="px-4 py-2">{{ $payPeriod->start_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-2">{{ $payPeriod->end_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-2">
                                <span class="inline-flex px-2 py-1 text-xs rounded-full {{ $statusClass }}">
                                    {{ $payPeriod->status }}
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                <a href="/nomina/{{ $payPeriod->id }}/revisar" class="text-indigo-600 hover:underline">Revisar</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-4 text-center text-gray-500">No hay períodos de nómina.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $payPeriods->links() }}
        </div>
    @endif
</div>
