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
use Illuminate\Database\Eloquent\Model;
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
        private string $stage,
        private string $lease,
    ) {}

    public function assertActive(): void
    {
        PayrollContextLocker::assertActive($this->lease);
    }

    public function assertOwns(Model $model): void
    {
        PayrollContextLocker::assertOwns($this->lease, $model);
    }

    public function assertStage(string $stage): void
    {
        if ($this->stage !== $stage) {
            throw new LogicException("Locked payroll context was not issued for the {$stage} mutation stage.");
        }

        PayrollContextLocker::assertStage($this->lease, $stage);
    }

    /** @param  array<string, mixed>  $attributes */
    public function createOwnedProfile(array $attributes): WorkScheduleProfile
    {
        $this->assertStage(PayrollContextLocker::STAGE_PROFILES);

        return PayrollContextLocker::createOwnedProfile($this->lease, $this->company, $attributes);
    }

    public function payPeriod(int $id): PayPeriod
    {
        $model = $this->payPeriods->get($id)
            ?? throw new LogicException("PayPeriod [{$id}] was not requested for this payroll context.");
        $this->assertOwns($model);

        return $model;
    }

    public function employee(int $id): Employee
    {
        $model = $this->employees->get($id)
            ?? throw new LogicException("Employee [{$id}] was not requested for this payroll context.");
        $this->assertOwns($model);

        return $model;
    }

    public function profile(int $id): WorkScheduleProfile
    {
        $model = $this->profiles->get($id)
            ?? throw new LogicException("WorkScheduleProfile [{$id}] was not requested for this payroll context.");
        $this->assertOwns($model);

        return $model;
    }

    public function publication(int $id): WorkScheduleProfilePublication
    {
        $model = $this->publications->get($id)
            ?? throw new LogicException("WorkScheduleProfilePublication [{$id}] was not requested for this payroll context.");
        $this->assertOwns($model);

        return $model;
    }

    public function assignment(int $id): EmployeeScheduleAssignment
    {
        $model = $this->assignments->get($id)
            ?? throw new LogicException("EmployeeScheduleAssignment [{$id}] was not requested for this payroll context.");
        $this->assertOwns($model);

        return $model;
    }

    public function rawMark(int $id): RawMark
    {
        $model = $this->rawMarks->get($id)
            ?? throw new LogicException("RawMark [{$id}] was not requested for this payroll context.");
        $this->assertOwns($model);

        return $model;
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
            $this->stage,
            $this->lease,
        );
    }
}
