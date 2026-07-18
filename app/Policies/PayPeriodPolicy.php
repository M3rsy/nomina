<?php

namespace App\Policies;

use App\Models\PayPeriod;
use App\Models\User;

class PayPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pay_periods.view');
    }

    public function view(User $user, PayPeriod $payPeriod): bool
    {
        if (! $user->can('pay_periods.view')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->company_id === $payPeriod->company_id;
    }

    public function manage(User $user, ?PayPeriod $payPeriod = null): bool
    {
        if (! $user->can('pay_periods.manage')) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($payPeriod === null) {
            return true;
        }

        return $user->company_id === $payPeriod->company_id;
    }
}
