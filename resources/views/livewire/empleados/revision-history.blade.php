<div class="bg-white p-6 rounded-lg shadow">
    <h2 class="text-lg font-semibold mb-4">Historial de cambios sensibles</h2>

    @if ($revisions->isEmpty())
        <p class="text-gray-500">No hay cambios registrados.</p>
    @else
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-2 text-left">Fecha</th>
                    <th class="px-4 py-2 text-left">Usuario</th>
                    <th class="px-4 py-2 text-left">Campo</th>
                    <th class="px-4 py-2 text-left">Valor anterior</th>
                    <th class="px-4 py-2 text-left">Valor nuevo</th>
                    <th class="px-4 py-2 text-left">Motivo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($revisions as $revision)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $revision->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2">{{ $revision->user?->email ?? 'Sistema' }}</td>
                        <td class="px-4 py-2">{{ $revision->field }}</td>
                        <td class="px-4 py-2">{{ $revision->old_value ?? '-' }}</td>
                        <td class="px-4 py-2">{{ $revision->new_value ?? '-' }}</td>
                        <td class="px-4 py-2">{{ $revision->reason ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
