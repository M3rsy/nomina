<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;

class UploadedFilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('files.view') && $this->canAccessActiveCompany($user);
    }

    public function view(User $user, UploadedFile $uploadedFile): bool
    {
        if (! $user->can('files.view')) {
            return false;
        }

        return $this->belongsToActiveCompany($user, $uploadedFile);
    }

    public function create(User $user): bool
    {
        return $user->can('files.upload') && $this->canAccessActiveCompany($user);
    }

    public function delete(User $user, UploadedFile $uploadedFile): bool
    {
        if (! $user->can('files.delete')) {
            return false;
        }

        return $this->belongsToActiveCompany($user, $uploadedFile);
    }

    public function manage(User $user, UploadedFile $uploadedFile): bool
    {
        if (! $user->can('files.manage')) {
            return false;
        }

        return $this->belongsToActiveCompany($user, $uploadedFile);
    }

    private function belongsToActiveCompany(User $user, UploadedFile $uploadedFile): bool
    {
        $companyId = $this->activeCompanyId();

        return $this->canAccessActiveCompany($user, $companyId) && $companyId === $uploadedFile->company_id;
    }

    private function canAccessActiveCompany(User $user, ?int $companyId = null): bool
    {
        $companyId ??= $this->activeCompanyId();

        if ($companyId === null) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->company_id === $companyId;
    }

    private function activeCompanyId(): ?int
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return null;
        }

        return Company::query()
            ->whereKey($company->id)
            ->where('is_active', true)
            ->exists()
                ? $company->id
                : null;
    }
}
