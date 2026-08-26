<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\Employee;
use App\Models\PayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class PayrollPeriodReviewSnapshot
{
    public function __construct(
        private PayrollShiftEvaluationResolver $evaluationResolver,
        private PayrollShiftEvaluator $shiftEvaluator,
        private HolidayCalendar $holidayCalendar,
    ) {}

    /** @return array{reviews: Collection<int, PayrollShiftReview>, blockers: Collection<int, array>, absences: Collection<int, array>} */
    public function forPeriod(PayPeriod $period, ?HolidayCalendarContext $calendarContext = null, bool $includeBlockers = true): array
    {
        return $this->materialize($this->captureForPeriod($period, $calendarContext), $includeBlockers);
    }

    /** @param Collection<int, Employee> $employees
     * @return array{reviews: Collection<int, PayrollShiftReview>, blockers: Collection<int, array>, absences: Collection<int, array>} */
    public function forEmployees(PayPeriod $period, Collection $employees, ?HolidayCalendarContext $calendarContext = null, bool $includeBlockers = true): array
    {
        return $this->materialize($this->captureForEmployees($period, $employees, $calendarContext), $includeBlockers);
    }

    public function captureForPeriod(PayPeriod $period, ?HolidayCalendarContext $calendarContext = null): PayrollPeriodReviewSnapshotContext
    {
        $employees = Employee::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();

        return $this->captureForEmployees($period, $employees, $calendarContext);
    }

    /** @param Collection<int, Employee> $employees */
    public function captureForEmployees(PayPeriod $period, Collection $employees, ?HolidayCalendarContext $calendarContext = null): PayrollPeriodReviewSnapshotContext
    {
        if ($employees->contains(fn (mixed $employee): bool => ! $employee instanceof Employee || $employee->company_id !== $period->company_id)) {
            throw new InvalidArgumentException('Employees must belong to the payroll period company.');
        }

        $employees = new EloquentCollection($employees->unique('id')->values()->all());
        $start = CarbonImmutable::parse($period->start_date);
        $end = CarbonImmutable::parse($period->end_date);
        $calendarContext ??= $this->holidayCalendar->capture($period->company, $start, $end);

        return new PayrollPeriodReviewSnapshotContext($period, $employees, $calendarContext, PayrollPeriodSnapshotData::capture($period, $employees));
    }

    /** @param callable(PayrollShiftReview): void $consume */
    public function forEachReview(PayrollPeriodReviewSnapshotContext $context, callable $consume): void
    {
        $start = CarbonImmutable::parse($context->period->start_date);
        $end = CarbonImmutable::parse($context->period->end_date);

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            foreach ($context->employees as $employee) {
                if ($employee->hired_at !== null && $date->lt($employee->hired_at->startOfDay())) {
                    continue;
                }

                $consume($this->evaluationResolver->review($context->period, $employee, $date, $context->calendar, $context->data));
            }
        }
    }

    /** @return Collection<int, array> */
    public function blockers(PayrollPeriodReviewSnapshotContext $context): Collection
    {
        $blockers = collect();
        $this->forEachReview($context, function (PayrollShiftReview $review) use ($blockers): void {
            $evaluation = $this->shiftEvaluator->evaluate($review->occurrence, $review->analysis, $review->currentDecisions, $review->currentExceptions);
            foreach ($evaluation->blockers as $blocker) {
                $blockers->push([
                    'employee_id' => $review->employee->id,
                    'employee_name' => $review->employee->full_name,
                    'employee_external_id' => $review->employee->external_id,
                    'work_date' => $review->occurrence->workDate->toDateString(),
                    ...$blocker,
                ]);
            }
        });

        return $blockers;
    }

    /** @return Collection<int, array{employee: Employee, date: CarbonImmutable, attendance_exception: ?AttendanceException}> */
    public function absences(PayrollPeriodReviewSnapshotContext $context): Collection
    {
        $absences = collect();
        $this->forEachReview($context, function (PayrollShiftReview $review) use ($context, $absences): void {
            $date = $review->occurrence->workDate;
            if ($context->calendar->isHoliday($date) || ! $review->occurrence->schedule?->is_working_day || $review->occurrence->status !== ShiftOccurrence::NO_MARKS) {
                return;
            }
            $deficit = $review->analysis->deficits->firstWhere('kind', 'full_day_absence');
            $exception = $deficit === null ? null : $review->exceptionFor($deficit);
            $absences->push([
                'employee' => $review->employee,
                'date' => $date,
                'attendance_exception' => $exception?->decision === AttendanceException::GRANTED ? $exception : null,
            ]);
        });

        return $absences->sortBy([['employee.id', 'asc'], ['date', 'asc']])->values();
    }

    /** @return array{reviews: Collection<int, PayrollShiftReview>, blockers: Collection<int, array>, absences: Collection<int, array>} */
    private function materialize(PayrollPeriodReviewSnapshotContext $context, bool $includeBlockers): array
    {
        $reviews = collect();
        $this->forEachReview($context, fn (PayrollShiftReview $review) => $reviews->push($review));

        return [
            'reviews' => $reviews,
            'blockers' => $includeBlockers ? $this->blockers($context) : collect(),
            'absences' => $this->absences($context),
        ];
    }
}
