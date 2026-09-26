<?php

namespace App\Livewire\Usuarios;

use App\Models\User;
use App\Services\DatabaseSessionRevoker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function deactivate(int $id): void
    {
        $target = User::query()->findOrFail($id);

        $this->authorizeUserAction('update', $target);

        DB::transaction(function () use ($target): void {
            $target->forceFill(['is_active' => false])->save();
            app(DatabaseSessionRevoker::class)->revokeUser($target->id);
        });
    }

    public function delete(int $id): void
    {
        $target = User::query()->findOrFail($id);

        $this->authorizeUserAction('delete', $target);

        DB::transaction(function () use ($target): void {
            app(DatabaseSessionRevoker::class)->revokeUser($target->id);
            $target->delete();
        });
    }

    private function authorizeUserAction(string $ability, User $target): void
    {
        if ($target->is(Auth::user())) {
            throw new AuthorizationException;
        }

        $this->authorize($ability, $target);
    }

    public function render()
    {
        $this->authorize('viewAny', User::class);

        /** @var User $user */
        $user = Auth::user();
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
