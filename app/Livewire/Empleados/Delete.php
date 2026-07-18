<?php

namespace App\Livewire\Empleados;

use App\Models\Employee;
use Livewire\Component;

class Delete extends Component
{
    public Employee $employee;

    public function mount(Employee $employee): void
    {
        $this->authorize('delete', $employee);
        $this->employee = $employee;
    }

    public function destroy(): void
    {
        $this->authorize('delete', $this->employee);

        if ($this->employee->trashed()) {
            $this->addError('employee', 'El empleado ya está desactivado.');

            return;
        }

        $this->employee->delete();

        $this->dispatch('employee-deleted');
    }

    public function render()
    {
        return view('livewire.empleados.delete');
    }
}
