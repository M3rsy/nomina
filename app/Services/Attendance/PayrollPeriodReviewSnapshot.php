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

    /**
     * @return array{
     *   reviews:Collection<int, PayrollShiftReview>,
     *   blockers:Collection<int, array>,
     *   absences:Collection<int, array>
     * }
     */
    public function forPeriod(
        PayPeriod $period,
        ?HolidayCalendarContext $calendarContext = null,
    ): array {
        $employees = Employee::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        return $this->forEmployees($period, $employees, $calendarContext);
    }

    /**
     * Review only the supplied employees from this payroll period's company.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array{
     *   reviews:Collection<int, PayrollShiftReview>,
     *   blockers:Collection<int, array>,
     *   absences:Collection<int, array>
     * }
     */
    public function forEmployees(
        PayPeriod $period,
        Collection $employees,
        ?HolidayCalendarContext $calendarContext = null,
    ): array {
        if ($employees->contains(
            fn (mixed $employee): bool => ! $employee instanceof Employee
                || $employee->company_id !== $period->company_id,
        )) {
            throw new InvalidArgumentException('Employees must belong to the payroll period company.');
        }

        $employees = new EloquentCollection($employees->unique('id')->values()->all());
        $start = CarbonImmutable::parse($period->start_date);
        $end = CarbonImmutable::parse($period->end_date);
        $calendarContext ??= $this->holidayCalendar->capture($period->company, $start, $end);
        $data = PayrollPeriodSnapshotData::capture($period, $employees);
        $reviews = collect();
        $blockers = collect();
        $absences = collect();

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            foreach ($employees as $employee) {
                if ($employee->hired_at !== null && $date->lt($employee->hired_at->startOfDay())) {
                    continue;
                }

                $review = $this->evaluationResolver->review(
                    $period,
                    $employee,
                    $date,
                    $calendarContext,
                    $data,
                );
                $reviews->push($review);
                $evaluation = $this->shiftEvaluator->evaluate(
                    $review->occurrence,
                    $review->analysis,
                    $review->currentDecisions,
                    $review->currentExceptions,
                );

                foreach ($evaluation->blockers as $blocker) {
                    $blockers->push([
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->full_name,
                        'employee_external_id' => $employee->external_id,
                        'work_date' => $date->toDateString(),
                        ...$blocker,
                    ]);
                }

                if ($calendarContext->isHoliday($date)
                    || ! $review->occurrence->schedule?->is_working_day
                    || $review->occurrence->status !== ShiftOccurrence::NO_MARKS) {
                    continue;
                }

                $deficit = $review->analysis->deficits->firstWhere('kind', 'full_day_absence');
                $exception = $deficit === null ? null : $review->exceptionFor($deficit);
                $absences->push([
                    'employee' => $employee,
                    'date' => $date,
                    'attendance_exception' => $exception?->decision === AttendanceException::GRANTED
                        ? $exception
                        : null,
                ]);
            }
        }

        return [
            'reviews' => $reviews,
            'blockers' => $blockers,
            'absences' => $absences->sortBy([
                ['employee.id', 'asc'],
                ['date', 'asc'],
            ])->values(),
        ];
    }
}
