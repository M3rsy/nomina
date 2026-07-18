<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('holidays.view');
    }

    public function view(User $user, Holiday $holiday): bool
    {
        if (! $user->can('holidays.view')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $holiday->company_id;
    }

    public function create(User $user): bool
    {
        return $user->can('holidays.manage');
    }

    public function update(User $user, Holiday $holiday): bool
    {
        if (! $user->can('holidays.manage')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $holiday->company_id;
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        if (! $user->can('holidays.manage')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $holiday->company_id;
    }
}
