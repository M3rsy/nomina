<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
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
            $company = Company::query()->whereKey($companyId)->orderBy('id')->lockForUpdate()->firstOrFail();
            $targets = $resolveTargets($company);

            if (! $targets instanceof PayrollContextTargets) {
                throw new LogicException('Payroll context targets must use PayrollContextTargets.');
            }

            $payPeriods = $this->lockTargets(
                PayPeriod::withoutCompanyScope()->withTrashed(),
                $targets->payPeriodIds,
                $companyId,
                'pay period',
            );
            $profiles = $this->lockTargets(
                WorkScheduleProfile::withoutCompanyScope(),
                $targets->profileIds,
                $companyId,
                'work schedule profile',
            );
            $publications = $this->lockTargets(
                WorkScheduleProfilePublication::withoutCompanyScope(),
                $targets->publicationIds,
                $companyId,
                'work schedule profile publication',
            );
            $employees = $this->lockTargets(
                Employee::withoutCompanyScope()->withTrashed(),
                $targets->employeeIds,
                $companyId,
                'employee',
            );
            $assignments = $this->lockTargets(
                EmployeeScheduleAssignment::withoutCompanyScope(),
                $targets->assignmentIds,
                $companyId,
                'employee schedule assignment',
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
                $profiles,
                $publications,
                $employees,
                $assignments,
                $rawMarks,
                null,
            );
            if ($targets->holidayStart !== null) {
                $context = $context->withHolidayCalendar($this->holidayCalendar->captureLocked(
                    $context,
                    $targets->holidayStart,
                    $targets->holidayEnd,
                ));
            }
            $result = $work($context);

            if ($result === $context) {
                throw new LogicException('Locked payroll context cannot escape its transaction.');
            }

            return $result;
        });
    }

    /**
     * @param  Builder<PayPeriod|WorkScheduleProfile|WorkScheduleProfilePublication|Employee|EmployeeScheduleAssignment|RawMark>  $query
     * @param  list<int>  $ids
     * @return Collection<int, PayPeriod|WorkScheduleProfile|WorkScheduleProfilePublication|Employee|EmployeeScheduleAssignment|RawMark>
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
