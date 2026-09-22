<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\PayPeriod;
use Illuminate\Support\Collection;

class PayrollReadinessChecker
{
    public function __construct(
        private PayrollPeriodReviewSnapshot $snapshots,
    ) {}

    /** @return Collection<int, array{employee_id:int,employee_name:string,employee_external_id:string,work_date:string,code:string,candidate_key?:string,deficit_key?:string}> */
    public function blockers(
        PayPeriod $payPeriod,
        ?HolidayCalendarContext $calendarContext = null,
        ?PayrollPeriodReviewSnapshotContext $snapshot = null,
    ): Collection {
        $snapshot ??= $this->snapshots->captureForPeriod($payPeriod, $calendarContext);
        $blockers = $this->snapshots->blockers($snapshot);
        $missingIdentity = Employee::withoutCompanyScope()
            ->where('company_id', $payPeriod->company_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('hired_at')->orWhereDate('hired_at', '<=', $payPeriod->end_date))
            ->where(fn ($query) => $query->whereNull('job_title')->orWhere('job_title', ''))
            ->get();

        return $blockers->concat($missingIdentity->map(fn (Employee $employee): array => [
            'employee_id' => $employee->id,
            'employee_name' => $employee->full_name,
            'employee_external_id' => $employee->external_id,
            'work_date' => $payPeriod->start_date->toDateString(),
            'code' => 'missing_payment_identity',
        ]));
    }
}
