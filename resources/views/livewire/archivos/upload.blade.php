<div class="max-w-2xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Subir archivo de marcas</h1>

    <form wire:submit="store" class="bg-white p-6 rounded-lg shadow space-y-4">
        <div>
            <label for="pay_period_id" class="block text-sm font-medium text-gray-700">Período de nómina</label>
            <select id="pay_period_id" wire:model="pay_period_id" class="mt-1 block w-full rounded border-gray-300 shadow-sm">
                <option value="">Seleccione...</option>
                @foreach ($payPeriods as $period)
                    <option value="{{ $period->id }}">{{ $period->name ?? $period->slug }}</option>
                @endforeach
            </select>
            @error('pay_period_id') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <label for="upload" class="block text-sm font-medium text-gray-700">Archivo (.txt o .dat)</label>
            <input id="upload" type="file" wire:model="upload" accept=".txt,.dat" class="mt-1 block w-full">
            @error('upload') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div class="text-sm text-gray-600">
            Tamaño máximo: 5 MB.
        </div>

        <button type="submit" class="bg-indigo-600 text-white py-2 px-4 rounded hover:bg-indigo-700">Subir</button>
    </form>
</div>
