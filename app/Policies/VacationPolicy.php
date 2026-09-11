<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vacation;

class VacationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('vacations.view');
    }

    public function view(User $user, Vacation $vacation): bool
    {
        return $user->can('vacations.view')
            && ($user->hasRole('super_admin') || $user->company_id === $vacation->company_id);
    }

    public function create(User $user): bool
    {
        return $user->can('vacations.manage');
    }

    public function cancel(User $user, Vacation $vacation): bool
    {
        return $user->can('vacations.manage')
            && ($user->hasRole('super_admin') || $user->company_id === $vacation->company_id);
    }
}
