<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkScheduleProfile;

class WorkScheduleProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('work_schedules.view');
    }

    public function view(User $user, WorkScheduleProfile $profile): bool
    {
        if (! $user->can('work_schedules.view')) {
            return false;
        }

        return $user->hasRole('super_admin')
            || $user->company_id === $profile->company_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, WorkScheduleProfile $profile): bool
    {
        return $this->canManage($user);
    }

    public function retire(User $user, WorkScheduleProfile $profile): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole('super_admin')
            && $user->can('work_schedules.manage');
    }
}
