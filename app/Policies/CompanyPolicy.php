<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->hasRole('super_admin')) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Company $company): bool
    {
        return true;
    }

    public function activate(User $user, Company $company): bool
    {
        return true;
    }

    public function delete(User $user, Company $company): bool
    {
        return true;
    }
}
