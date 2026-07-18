<?php

namespace App\Livewire\Empresas;

use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function delete(int $id): void
    {
        $company = Company::findOrFail($id);
        $this->authorize('delete', $company);
        $company->delete();
    }

    public function toggle(int $id): void
    {
        $company = Company::findOrFail($id);
        $this->authorize('activate', $company);
        $company->is_active = ! $company->is_active;
        $company->save();
    }

    public function render()
    {
        $companies = Company::query()
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('slug', 'like', '%'.$this->search.'%')
                    ->orWhere('legal_id', 'like', '%'.$this->search.'%');
            })
            ->orderBy('name')
            ->paginate(10);

        return view('livewire.empresas.index', ['companies' => $companies]);
    }
}
