<?php

namespace App\Livewire\Usuarios;

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
        $this->authorize('viewAny', User::class);

        $user = auth()->user();
        $isSuperAdmin = $user->hasRole('super_admin');
        $companyId = current_company_id();

        $users = User::query()
            ->when($isSuperAdmin && $companyId !== null, function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->when(! $isSuperAdmin, function ($query) use ($user) {
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

        return view('livewire.usuarios.index', ['users' => $users]);
    }
}
