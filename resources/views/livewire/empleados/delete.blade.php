<x-ui.loading-button
    type="button"
    wire:click="destroy"
    target="destroy"
    loading-label="Retirando…"
    wire:confirm="¿Retirar a {{ $employee->full_name }} del directorio? El registro dejará de estar disponible, pero su historial se conservará y podrás restaurarlo."
    aria-label="Retirar del directorio a {{ $employee->full_name }}"
    class="inline-flex min-h-9 items-center rounded-lg border border-rose-100 bg-rose-50 px-3 py-1 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2"
>
    Retirar del directorio
</x-ui.loading-button>
