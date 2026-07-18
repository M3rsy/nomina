<?php

namespace App\Policies;

use App\Models\RawMark;
use App\Models\User;

class RawMarkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('marks.view');
    }

    public function view(User $user, RawMark $rawMark): bool
    {
        if (! $user->can('marks.view')) {
            return false;
        }

        return $this->ownsCompany($user, $rawMark);
    }

    public function manage(User $user, RawMark $rawMark): bool
    {
        if (! $user->can('marks.manage')) {
            return false;
        }

        return $this->ownsCompany($user, $rawMark);
    }

    public function edit(User $user, RawMark $rawMark): bool
    {
        if (! $user->can('marks.edit') && ! $user->can('marks.manage')) {
            return false;
        }

        return $this->ownsCompany($user, $rawMark);
    }

    public function update(User $user, RawMark $rawMark): bool
    {
        return $this->manage($user, $rawMark);
    }

    public function delete(User $user, RawMark $rawMark): bool
    {
        return $this->manage($user, $rawMark);
    }

    public function assign(User $user, RawMark $rawMark): bool
    {
        return $this->manage($user, $rawMark);
    }

    private function ownsCompany(User $user, RawMark $rawMark): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $rawMark->company_id;
    }
}
