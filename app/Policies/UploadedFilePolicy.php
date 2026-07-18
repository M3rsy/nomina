<?php

namespace App\Policies;

use App\Models\UploadedFile;
use App\Models\User;

class UploadedFilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('files.view');
    }

    public function view(User $user, UploadedFile $uploadedFile): bool
    {
        if (! $user->can('files.view')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $uploadedFile->company_id;
    }

    public function create(User $user): bool
    {
        return $user->can('files.upload');
    }

    public function delete(User $user, UploadedFile $uploadedFile): bool
    {
        if (! $user->can('files.delete')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $uploadedFile->company_id;
    }

    public function manage(User $user, UploadedFile $uploadedFile): bool
    {
        if (! $user->can('files.manage')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $uploadedFile->company_id;
    }
}
