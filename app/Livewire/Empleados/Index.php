<?php

namespace App\Livewire\Empleados;

use App\Models\Company;
use App\Models\Employee;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $filter = 'active';

    public function render()
    {
        $user = auth()->user();
        $companyId = current_company_id();

        $this->authorize('viewAny', Employee::class);

        $employees = Employee::query()
            ->when($this->filter === 'active', function ($query) {
                $query->where('is_active', true);
            })
            ->when($this->search, function ($query) {
                $search = '%'.$this->search.'%';
                $query->where(function ($q) use ($search) {
                    $q->where('external_id', 'like', $search)
                        ->orWhere('dni', 'like', $search)
                        ->orWhere('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(10);

        return view('livewire.empleados.index', [
            'employees' => $employees,
            'companies' => $user->hasRole('super_admin') ? Company::orderBy('name')->get() : null,
            'currentCompanyId' => $companyId,
        ]);
    }
}
