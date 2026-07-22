<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\PayPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class PayPeriodRangeGuard
{
    public function assertAvailable(
        int $companyId,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?int $exceptPayPeriodId = null,
    ): void {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->startOfDay();

        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

        $overlaps = PayPeriod::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->when($exceptPayPeriodId !== null, fn ($query) => $query->whereKeyNot($exceptPayPeriodId))
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();

        if ($overlaps) {
            throw new InvalidArgumentException('Las fechas se superponen con otro período de la empresa.');
        }
    }
}
