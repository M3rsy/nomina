<div>
    <x-ui.loading-button
        type="button"
        wire:click="restore"
        target="restore"
        loading-label="Restaurando…"
        wire:confirm="¿Restaurar a {{ $employeeName }} en el directorio?"
        aria-label="Restaurar a {{ $employeeName }}"
        class="inline-flex min-h-9 items-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
    >
        Restaurar
    </x-ui.loading-button>

    @error('employee')
        <p class="mt-1 text-xs font-semibold text-danger" role="alert">{{ $message }}</p>
    @enderror
</div>
