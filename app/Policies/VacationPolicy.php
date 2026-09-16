<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Models\Vacation;
use App\Services\CurrentCompany;

class VacationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('vacations.view') && $this->canAccessActiveCompany($user);
    }

    public function view(User $user, Vacation $vacation): bool
    {
        if (! $user->can('vacations.view')) {
            return false;
        }

        return $this->belongsToActiveCompany($user, $vacation);
    }

    public function create(User $user): bool
    {
        return $user->can('vacations.manage') && $this->canAccessActiveCompany($user);
    }

    public function cancel(User $user, Vacation $vacation): bool
    {
        if (! $user->can('vacations.manage')) {
            return false;
        }

        return $this->belongsToActiveCompany($user, $vacation);
    }

    private function belongsToActiveCompany(User $user, Vacation $vacation): bool
    {
        $companyId = $this->activeCompanyId();

        return $this->canAccessActiveCompany($user, $companyId) && $companyId === $vacation->company_id;
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
