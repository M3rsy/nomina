<?php

namespace App\Services\Payroll\Policy;

final class InCodePayrollPolicyCatalog implements PayrollPolicyCatalog
{
    public function resolve(string $key): PayrollPolicyDefinition
    {
        return match ($key) {
            'schedule-overlap-v1' => new ScheduleOverlapPolicyDefinition(),
            'duration-first-v2' => new DurationFirstPolicyDefinition(),
            default => throw new UnsupportedPayrollPolicy($key),
        };
    }
}
