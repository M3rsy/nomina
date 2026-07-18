<button wire:click="toggle" class="text-sm text-gray-600 hover:text-indigo-600">
    {{ $employee->is_active ? 'Desactivar' : 'Activar' }}
</button>
