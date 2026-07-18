<?php

namespace App\Livewire\Usuarios;

use App\Models\Company;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function render()
    {
        $user = auth()->user();
        $companyId = current_company_id();

        $users = User::query()
            ->when(! $user->hasRole('super_admin'), function ($query) use ($user) {
                $query->where('company_id', $user->company_id);
            })
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(10);

        return view('livewire.usuarios.index', [
            'users' => $users,
            'companies' => $user->hasRole('super_admin') ? Company::all() : null,
        ]);
    }
}
