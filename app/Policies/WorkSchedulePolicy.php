<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkSchedule;

class WorkSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('work_schedules.view');
    }

    public function view(User $user, WorkSchedule $schedule): bool
    {
        if (! $user->can('work_schedules.view')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $schedule->company_id;
    }

    public function create(User $user): bool
    {
        return $user->can('work_schedules.manage');
    }

    public function update(User $user, WorkSchedule $schedule): bool
    {
        if (! $user->can('work_schedules.manage')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $schedule->company_id;
    }

    public function delete(User $user, WorkSchedule $schedule): bool
    {
        if (! $user->can('work_schedules.manage')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $schedule->company_id;
    }
}
