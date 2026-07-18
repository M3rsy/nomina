<?php

namespace App\Livewire\Empresas;

use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ToggleActivate extends Component
{
    public function toggle(int $id): void
    {
        $company = Company::findOrFail($id);
        $this->authorize('activate', $company);
        $company->is_active = ! $company->is_active;
        $company->save();
    }

    public function render()
    {
        return view('livewire.empresas.toggle-activate');
    }
}
