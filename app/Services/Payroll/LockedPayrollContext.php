<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Services\Attendance\HolidayCalendarContext;
use Illuminate\Support\Collection;
use LogicException;

final readonly class LockedPayrollContext
{
    /**
     * @param  Collection<int, PayPeriod>  $payPeriods
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, RawMark>  $rawMarks
     */
    public function __construct(
        public Company $company,
        public Collection $payPeriods,
        public Collection $employees,
        public Collection $rawMarks,
        public ?HolidayCalendarContext $holidayCalendar,
    ) {}

    public function payPeriod(int $id): PayPeriod
    {
        return $this->payPeriods->get($id)
            ?? throw new LogicException("PayPeriod [{$id}] was not requested for this payroll context.");
    }

    public function employee(int $id): Employee
    {
        return $this->employees->get($id)
            ?? throw new LogicException("Employee [{$id}] was not requested for this payroll context.");
    }

    public function rawMark(int $id): RawMark
    {
        return $this->rawMarks->get($id)
            ?? throw new LogicException("RawMark [{$id}] was not requested for this payroll context.");
    }
}
