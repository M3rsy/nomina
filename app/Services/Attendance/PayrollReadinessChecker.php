<?php

namespace App\Services\Attendance;

use App\Models\PayPeriod;
use Illuminate\Support\Collection;

class PayrollReadinessChecker
{
    public function __construct(
        private PayrollPeriodReviewSnapshot $snapshots,
    ) {}

    /** @return Collection<int, array{employee_id:int,employee_name:string,employee_external_id:string,work_date:string,code:string,candidate_key?:string}> */
    public function blockers(
        PayPeriod $payPeriod,
        ?HolidayCalendarContext $calendarContext = null,
        ?array $snapshot = null,
    ): Collection {
        return ($snapshot ?? $this->snapshots->forPeriod($payPeriod, $calendarContext))['blockers'];
    }
}
