<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\RawMark;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ShiftOccurrenceResolver
{
    public function resolve(Employee $employee, CarbonInterface|string $workDate): ShiftOccurrence
    {
        $date = CarbonImmutable::parse($workDate)->startOfDay();
        $assignment = $this->assignmentFor($employee, $date);

        if ($assignment === null) {
            return $this->emptyOccurrence($date, ShiftOccurrence::MISSING_ASSIGNMENT);
        }

        $schedule = $this->scheduleFor($employee, $assignment, $date);

        if ($schedule === null) {
            return $this->emptyOccurrence($date, ShiftOccurrence::MISSING_SCHEDULE, $assignment);
        }

        [$scheduledStart, $scheduledEnd, $windowStart, $windowEnd] = $this->bounds($employee, $date, $schedule);
        $marks = RawMark::withoutCompanyScope()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['valid', 'corrected'])
            ->where('event_at', '>=', $windowStart)
            ->where('event_at', '<', $windowEnd)
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();

        return new ShiftOccurrence(
            workDate: $date,
            assignment: $assignment,
            schedule: $schedule,
            scheduledStart: $scheduledStart,
            scheduledEnd: $scheduledEnd,
            marks: $marks,
            status: match ($marks->count()) {
                0 => ShiftOccurrence::NO_MARKS,
                1 => ShiftOccurrence::MISSING_PAIR,
                2 => ShiftOccurrence::RESOLVED,
                default => ShiftOccurrence::AMBIGUOUS,
            },
        );
    }

    public function workDateFor(Employee $employee, CarbonInterface|string $eventAt): CarbonImmutable
    {
        $instant = CarbonImmutable::parse($eventAt);
        $calendarDate = $instant->startOfDay();

        foreach ([$calendarDate->subDay(), $calendarDate, $calendarDate->addDay()] as $workDate) {
            $assignment = $this->assignmentFor($employee, $workDate);
            $schedule = $assignment === null ? null : $this->scheduleFor($employee, $assignment, $workDate);

            if ($schedule === null) {
                continue;
            }

            [, , $windowStart, $windowEnd] = $this->bounds($employee, $workDate, $schedule);

            if ($instant->gte($windowStart) && $instant->lt($windowEnd)) {
                return $workDate;
            }
        }

        return $calendarDate;
    }

    private function assignmentFor(Employee $employee, CarbonImmutable $date): ?EmployeeScheduleAssignment
    {
        return EmployeeScheduleAssignment::withoutCompanyScope()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @return array{CarbonImmutable|null, CarbonImmutable|null, CarbonImmutable, CarbonImmutable}
     */
    private function bounds(Employee $employee, CarbonImmutable $date, WorkSchedule $schedule): array
    {
        [$start, $end] = $this->scheduledInterval($date, $schedule);

        return [
            $start,
            $end,
            $this->boundaryBetween($employee, $date->subDay(), $date),
            $this->boundaryBetween($employee, $date, $date->addDay()),
        ];
    }

    private function boundaryBetween(Employee $employee, CarbonImmutable $leftDate, CarbonImmutable $rightDate): CarbonImmutable
    {
        // Midpoints partition adjacent work dates; an exact scheduled exit stays with the earlier shift.
        $boundary = $this->midpoint($this->anchorFor($employee, $leftDate), $this->anchorFor($employee, $rightDate));
        $assignment = $this->assignmentFor($employee, $leftDate);
        $schedule = $assignment === null ? null : $this->scheduleFor($employee, $assignment, $leftDate);
        [, $scheduledEnd] = $schedule === null ? [null, null] : $this->scheduledInterval($leftDate, $schedule);

        return $scheduledEnd !== null && $boundary->lte($scheduledEnd)
            ? $scheduledEnd->addSecond()
            : $boundary;
    }

    private function anchorFor(Employee $employee, CarbonImmutable $date): CarbonImmutable
    {
        $assignment = $this->assignmentFor($employee, $date);
        $schedule = $assignment === null ? null : $this->scheduleFor($employee, $assignment, $date);
        [$start, $end] = $schedule === null ? [null, null] : $this->scheduledInterval($date, $schedule);

        return $start === null ? $date->addHours(12) : $this->midpoint($start, $end);
    }

    private function scheduleFor(
        Employee $employee,
        EmployeeScheduleAssignment $assignment,
        CarbonImmutable $date,
    ): ?WorkSchedule {
        return WorkSchedule::withoutCompanyScope()
            ->where('company_id', $employee->company_id)
            ->where('work_schedule_profile_id', $assignment->work_schedule_profile_id)
            ->where('day_of_week', $date->dayOfWeek)
            ->first();
    }

    /** @return array{CarbonImmutable|null, CarbonImmutable|null} */
    private function scheduledInterval(CarbonImmutable $date, WorkSchedule $schedule): array
    {
        if (! $schedule->is_working_day || $schedule->start_time === null || $schedule->end_time === null) {
            return [null, null];
        }

        $start = $date->setTimeFromTimeString($schedule->start_time);
        $end = $date->setTimeFromTimeString($schedule->end_time);

        return [$start, $end->lte($start) ? $end->addDay() : $end];
    }

    private function midpoint(CarbonImmutable $start, CarbonImmutable $end): CarbonImmutable
    {
        return $start->addSeconds((int) ($start->diffInSeconds($end) / 2));
    }

    private function emptyOccurrence(
        CarbonImmutable $date,
        string $status,
        ?EmployeeScheduleAssignment $assignment = null,
    ): ShiftOccurrence {
        return new ShiftOccurrence($date, $assignment, null, null, null, Collection::empty(), $status);
    }
}
