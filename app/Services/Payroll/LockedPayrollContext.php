<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\HolidayCalendarContext;
use Illuminate\Support\Collection;
use LogicException;

final readonly class LockedPayrollContext
{
    /**
     * @param  Collection<int, PayPeriod>  $payPeriods
     * @param  Collection<int, WorkScheduleProfile>  $profiles
     * @param  Collection<int, WorkScheduleProfilePublication>  $publications
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, EmployeeScheduleAssignment>  $assignments
     * @param  Collection<int, RawMark>  $rawMarks
     */
    public function __construct(
        public Company $company,
        public Collection $payPeriods,
        public Collection $profiles,
        public Collection $publications,
        public Collection $employees,
        public Collection $assignments,
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

    public function profile(int $id): WorkScheduleProfile
    {
        return $this->profiles->get($id)
            ?? throw new LogicException("WorkScheduleProfile [{$id}] was not requested for this payroll context.");
    }

    public function publication(int $id): WorkScheduleProfilePublication
    {
        return $this->publications->get($id)
            ?? throw new LogicException("WorkScheduleProfilePublication [{$id}] was not requested for this payroll context.");
    }

    public function assignment(int $id): EmployeeScheduleAssignment
    {
        return $this->assignments->get($id)
            ?? throw new LogicException("EmployeeScheduleAssignment [{$id}] was not requested for this payroll context.");
    }

    public function rawMark(int $id): RawMark
    {
        return $this->rawMarks->get($id)
            ?? throw new LogicException("RawMark [{$id}] was not requested for this payroll context.");
    }

    public function withHolidayCalendar(HolidayCalendarContext $holidayCalendar): self
    {
        return new self(
            $this->company,
            $this->payPeriods,
            $this->profiles,
            $this->publications,
            $this->employees,
            $this->assignments,
            $this->rawMarks,
            $holidayCalendar,
        );
    }
}
