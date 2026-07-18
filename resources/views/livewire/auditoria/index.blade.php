<div class="max-w-7xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Auditoría</h1>

    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap gap-4 items-end">
            <div>
                <label for="type" class="block text-sm font-medium text-gray-700">Tipo de evento</label>
                <select id="type" wire:model.live="type" class="mt-1 block w-56 rounded border-gray-300 shadow-sm">
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if ($isSuper)
                <div>
                    <label for="company_id" class="block text-sm font-medium text-gray-700">Empresa</label>
                    <select id="company_id" wire:model.live="company_id" class="mt-1 block w-56 rounded border-gray-300 shadow-sm">
                        <option value="">Todas</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label for="user" class="block text-sm font-medium text-gray-700">Usuario (correo)</label>
                <input id="user" type="text" wire:model.live.debounce.300ms="user" class="mt-1 block rounded border-gray-300 shadow-sm" placeholder="correo@ejemplo.com">
            </div>

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

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-2 text-left">Fecha</th>
                    <th class="px-4 py-2 text-left">Tipo</th>
                    <th class="px-4 py-2 text-left">Empresa</th>
                    <th class="px-4 py-2 text-left">Usuario</th>
                    <th class="px-4 py-2 text-left">Descripción</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $entry->createdAt->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2">{{ $entry->typeLabel }}</td>
                        <td class="px-4 py-2">{{ $entry->companyName ?? 'N/A' }}</td>
                        <td class="px-4 py-2">{{ $entry->userEmail ?? 'N/A' }}</td>
                        <td class="px-4 py-2">{{ $entry->description }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-4 text-center text-gray-500">No hay eventos de auditoría.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>
</div>
