<?php

namespace App\Livewire\Empleados;

use App\Models\Employee;
use Livewire\Component;

class RevisionHistory extends Component
{
    public Employee $employee;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
    }

    public function render()
    {
        $revisions = $this->employee->revisions()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('livewire.empleados.revision-history', [
            'revisions' => $revisions,
        ]);
    }
}
