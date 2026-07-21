<?php

namespace App\Services;

use App\Models\User;

final class AccountAccess
{
    public const COMPANY_ASSIGNMENT_MISSING = 'company_assignment_missing';

    public const COMPANY_INACTIVE = 'company_inactive';

    public const USER_INACTIVE = 'user_inactive';

    public const USER_MESSAGE = 'No se pudo acceder a la cuenta.';

    public function denialReason(User $user): ?string
    {
        if ($user->hasRole('super_admin')) {
            return null;
        }

        if (! $user->hasRole('company_admin')) {
            return $user->is_active ? null : self::USER_INACTIVE;
        }

        $company = $user->company;

        if ($company === null || $user->company_id === null) {
            return self::COMPANY_ASSIGNMENT_MISSING;
        }

        if (! $company->is_active) {
            return self::COMPANY_INACTIVE;
        }

        return $user->is_active ? null : self::USER_INACTIVE;
    }
}
