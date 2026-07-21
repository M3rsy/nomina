<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AccountAccess;

class UserPolicy
{
    public function __construct(private AccountAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->denialReason($user) === null && $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->can('users.view') && $this->sharesTenant($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->access->denialReason($user) === null && $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if (! $user->can('users.update')) {
            return false;
        }

        return $this->sharesTenant($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return false;
    }

    private function sharesTenant(User $user, User $model): bool
    {
        return $this->access->denialReason($user) === null
            && $user->company_id !== null
            && $user->company_id === $model->company_id;
    }
}
