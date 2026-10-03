<?php

namespace App\Livewire\Empleados;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Restore extends Component
{
    public int $employeeId;

    public string $employeeName;

    public function mount(Employee $employee): void
    {
        $this->authorize('restore', $employee);
        $this->employeeId = $employee->id;
        $this->employeeName = $employee->full_name;
    }

    public function restore(): void
    {
        $restored = DB::transaction(function (): bool {
            $employee = Employee::withoutCompanyScope()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($this->employeeId);

            $this->authorize('restore', $employee);

            if (! $employee->trashed()) {
                return false;
            }

            $employee->restore();

            return true;
        });

        if (! $restored) {
            $this->addError('employee', 'El empleado ya está en el directorio.');

            return;
        }

        $this->dispatch('employee-restored');
    }

    public function render()
    {
        return view('livewire.empleados.restore');
    }
}
