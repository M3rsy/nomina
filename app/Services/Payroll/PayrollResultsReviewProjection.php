<?php

namespace App\Services\Payroll;

use App\Models\PayPeriod;
use App\Models\PayrollResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PayrollResultsReviewProjection
{
    /** @return array<string, int> */
    public function summary(PayPeriod $period, ?int $employeeId, ?string $absence): array
    {
        $totals = $this->query($period, $employeeId, $absence)->selectRaw(
            'count(*) as total_days, count(distinct employee_id) as total_employees, sum(ordinary_minutes) as ordinary_minutes, sum(extra_25_minutes) as extra_25_minutes, sum(extra_50_minutes) as extra_50_minutes, sum(extra_75_minutes) as extra_75_minutes, sum(extra_100_minutes) as extra_100_minutes, sum(case when is_absence = 1 then 1 else 0 end) as absence_days, sum(approved_overtime_minutes) as approved_overtime_minutes',
        )->first();

        return [
            'total_employees' => (int) ($totals?->total_employees ?? 0),
            'total_days' => (int) ($totals?->total_days ?? 0),
            'ordinary_minutes' => (int) ($totals?->ordinary_minutes ?? 0),
            'extra_25_minutes' => (int) ($totals?->extra_25_minutes ?? 0),
            'extra_50_minutes' => (int) ($totals?->extra_50_minutes ?? 0),
            'extra_75_minutes' => (int) ($totals?->extra_75_minutes ?? 0),
            'extra_100_minutes' => (int) ($totals?->extra_100_minutes ?? 0),
            'absence_days' => (int) ($totals?->absence_days ?? 0),
            'approved_overtime_minutes' => (int) ($totals?->approved_overtime_minutes ?? 0),
        ];
    }

    /** @return LengthAwarePaginator<int, PayrollResult> */
    public function page(PayPeriod $period, ?int $employeeId, ?string $absence): LengthAwarePaginator
    {
        return $this->query($period, $employeeId, $absence)->orderBy('employee_id')->orderBy('date')->paginate(50);
    }

    public function evidence(PayPeriod $period, ?int $resultId): ?PayrollResult
    {
        if ($resultId === null) {
            return null;
        }

        return $this->query($period, null, null)->find($resultId);
    }

    private function query(PayPeriod $period, ?int $employeeId, ?string $absence)
    {
        return PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $period->id)
            ->where('result_generation', $period->current_result_generation)
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($absence === 'absence', fn ($query) => $query->where('is_absence', true))
            ->when($absence === 'worked', fn ($query) => $query->where('is_absence', false));
    }
}
