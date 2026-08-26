<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\PayPeriod;
use Illuminate\Support\Collection;

final readonly class PayrollPeriodReviewSnapshotContext
{
    /** @param Collection<int, Employee> $employees */
    public function __construct(
        public PayPeriod $period,
        public Collection $employees,
        public HolidayCalendarContext $calendar,
        public PayrollPeriodSnapshotData $data,
    ) {}
}
