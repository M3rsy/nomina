<?php

namespace App\Livewire\Empresas;

use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Create extends Component
{
    public string $name = '';

    public ?string $legal_id = null;

    public bool $is_active = true;

    public function save(): void
    {
        $this->authorize('create', Company::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_id' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede exceder 150 caracteres.',
            'legal_id.max' => 'El RTN no puede exceder 50 caracteres.',
        ]);

        Company::create($validated);

        $this->redirect('/empresas', navigate: true);
    }

    public function render()
    {
        return view('livewire.empresas.create');
    }
}
