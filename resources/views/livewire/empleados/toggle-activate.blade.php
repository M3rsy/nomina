<x-ui.loading-button
    type="button"
    wire:click="toggle"
    target="toggle"
    loading-label="Actualizando…"
    wire:confirm="{{ $employee->is_active ? '¿Desactivar a '.$employee->full_name.'? Podrás volver a activarlo desde el filtro de inactivos.' : '¿Activar a '.$employee->full_name.'?' }}"
    aria-label="{{ $employee->is_active ? 'Desactivar' : 'Activar' }} a {{ $employee->full_name }}"
    class="inline-flex min-h-9 items-center rounded-lg border px-3 py-1 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 {{ $employee->is_active ? 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100' : 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}"
>
    {{ $employee->is_active ? 'Desactivar' : 'Activar' }}
</x-ui.loading-button>
