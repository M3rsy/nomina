<?php

namespace App\Livewire\Empleados;

use App\Models\Employee;
use Livewire\Component;

class ToggleActivate extends Component
{
    public Employee $employee;

    public function mount(Employee $employee): void
    {
        $this->authorize('activate', $employee);
        $this->employee = $employee;
    }

    public function toggle(): void
    {
        $this->authorize('activate', $this->employee);

        $this->employee->update([
            'is_active' => ! $this->employee->is_active,
        ]);
    }

    public function render()
    {
        return view('livewire.empleados.toggle-activate');
    }
}
