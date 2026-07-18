<?php

namespace App\Livewire\Empresas;

use App\Models\Company;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Edit extends Component
{
    public Company $company;

    public string $name = '';

    public ?string $legal_id = null;

    public bool $is_active = true;

    public function mount(Company $company): void
    {
        $this->authorize('update', $company);

        $this->company = $company;
        $this->name = $company->name;
        $this->legal_id = $company->legal_id;
        $this->is_active = $company->is_active;
    }

    public function save(): void
    {
        $this->authorize('update', $this->company);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_id' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede exceder 150 caracteres.',
            'legal_id.max' => 'El RTN no puede exceder 50 caracteres.',
        ]);

        $this->company->update($validated);

        $this->redirect('/empresas', navigate: true);
    }

    public function render()
    {
        return view('livewire.empresas.edit');
    }
}
