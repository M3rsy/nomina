<?php

namespace App\Services\Payroll\Policy;

abstract readonly class PayrollPolicyDefinition
{
    protected const SECONDS_PER_MINUTE = 60;

    final public function completeElapsedMinutes(int $elapsedSeconds): int
    {
        return max(0, intdiv($elapsedSeconds, self::SECONDS_PER_MINUTE));
    }
}
