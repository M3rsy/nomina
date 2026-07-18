<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Jornadas</h1>
        </div>

        @if ($showSuccess)
            <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                Los cambios se guardaron correctamente.
            </div>
        @endif

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Día</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Día laborable</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Horas base</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Notas</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach ($schedules as $index => $schedule)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $schedule['day_name'] }}</td>
                            <td class="px-4 py-3 text-sm">
                                <input
                                    type="checkbox"
                                    wire:model="schedules.{{ $index }}.is_working_day"
                                    class="h-4 w-4 rounded border-gray-300 text-indigo-600"
                                />
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <input
                                    type="number"
                                    step="0.25"
                                    min="0"
                                    max="24"
                                    wire:model="schedules.{{ $index }}.base_ordinary_hours"
                                    class="w-20 rounded border-gray-300"
                                />
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <input
                                    type="text"
                                    wire:model="schedules.{{ $index }}.notes"
                                    class="w-full rounded border-gray-300"
                                />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @can('work_schedules.manage')
            <div class="mt-6">
                <button
                    wire:click="save"
                    class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-700"
                >
                    Guardar cambios
                </button>
            </div>
        @endcan
    </div>
</div>