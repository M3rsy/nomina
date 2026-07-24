<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Services\Attendance\HolidayCalendar;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class PayrollContextLocker
{
    public function __construct(private HolidayCalendar $holidayCalendar) {}

    public function within(int $companyId, Closure $resolveTargets, Closure $work): mixed
    {
        return DB::transaction(function () use ($companyId, $resolveTargets, $work): mixed {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $targets = $resolveTargets($company);

            if (! $targets instanceof PayrollContextTargets) {
                throw new LogicException('Payroll context targets must use PayrollContextTargets.');
            }

            $holidayContext = $targets->holidayStart === null
                ? null
                : $this->holidayCalendar->capture(
                    $company,
                    $targets->holidayStart,
                    $targets->holidayEnd,
                );
            $payPeriods = $this->lockTargets(
                PayPeriod::withoutCompanyScope()->withTrashed(),
                $targets->payPeriodIds,
                $companyId,
                'pay period',
            );
            $employees = $this->lockTargets(
                Employee::withoutCompanyScope()->withTrashed(),
                $targets->employeeIds,
                $companyId,
                'employee',
            );
            $rawMarks = $this->lockTargets(
                RawMark::withoutCompanyScope(),
                $targets->rawMarkIds,
                $companyId,
                'raw mark',
            );
            $context = new LockedPayrollContext(
                $company,
                $payPeriods,
                $employees,
                $rawMarks,
                $holidayContext,
            );
            $result = $work($context);

            if ($result === $context) {
                throw new LogicException('Locked payroll context cannot escape its transaction.');
            }

            return $result;
        });
    }

    /**
     * @param  Builder<PayPeriod|Employee|RawMark>  $query
     * @param  list<int>  $ids
     * @return Collection<int, PayPeriod|Employee|RawMark>
     */
    private function lockTargets(Builder $query, array $ids, int $companyId, string $type): Collection
    {
        $ids = collect($ids)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $models = $query->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($models->count() !== $ids->count()
            || $models->contains(fn (mixed $model): bool => $model->company_id !== $companyId)) {
            throw ValidationException::withMessages([
                'payroll_context' => "A requested {$type} is missing or belongs to another company.",
            ]);
        }

        return $models;
    }
}
