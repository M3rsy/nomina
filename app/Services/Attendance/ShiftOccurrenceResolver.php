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
    public function __construct(private AttendanceFactGenerationTracker $factGenerations) {}

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
            // Adjacent facts can move a boundary, so they also invalidate this occurrence identity.
            factGeneration: $this->factGenerations->currentForDates($employee, [
                $date->subDay(),
                $date,
                $date->addDay(),
            ]),
        );
    }

    public function workDateFor(
        Employee $employee,
        CarbonInterface|string $eventAt,
        ?int $ignoreRawMarkId = null,
    ): CarbonImmutable {
        $instant = CarbonImmutable::parse($eventAt);
        $calendarDate = $instant->startOfDay();

        foreach ([$calendarDate->subDay(), $calendarDate, $calendarDate->addDay()] as $workDate) {
            $assignment = $this->assignmentFor($employee, $workDate);
            $schedule = $assignment === null ? null : $this->scheduleFor($employee, $assignment, $workDate);

            if ($schedule === null) {
                continue;
            }

            [, , $windowStart, $windowEnd] = $this->bounds(
                $employee,
                $workDate,
                $schedule,
                $instant,
                $ignoreRawMarkId,
            );

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
    private function bounds(
        Employee $employee,
        CarbonImmutable $date,
        WorkSchedule $schedule,
        ?CarbonImmutable $probe = null,
        ?int $ignoreRawMarkId = null,
    ): array {
        [$start, $end] = $this->scheduledInterval($date, $schedule);

        return [
            $start,
            $end,
            $this->boundaryBetween($employee, $date->subDay(), $date, $probe, $ignoreRawMarkId),
            $this->boundaryBetween($employee, $date, $date->addDay(), $probe, $ignoreRawMarkId),
        ];
    }

    private function boundaryBetween(
        Employee $employee,
        CarbonImmutable $leftDate,
        CarbonImmutable $rightDate,
        ?CarbonImmutable $probe = null,
        ?int $ignoreRawMarkId = null,
    ): CarbonImmutable {
        [$leftStart, $scheduledEnd] = $this->assignedInterval($employee, $leftDate);
        [$rightStart, $rightEnd] = $this->assignedInterval($employee, $rightDate);
        $boundary = $this->defaultBoundary(
            $leftDate,
            $leftStart,
            $scheduledEnd,
            $rightDate,
            $rightStart,
            $rightEnd,
        );

        if (! $this->canBridgeOvernightPair($employee, $leftStart, $scheduledEnd, $rightDate, $rightStart, $rightEnd)) {
            return $boundary;
        }

        $windowStart = $this->defaultBoundaryBetween($employee, $leftDate->subDay(), $leftDate);
        $windowEnd = $this->defaultBoundaryBetween($employee, $rightDate, $rightDate->addDay());
        $markInstants = RawMark::withoutCompanyScope()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['valid', 'corrected'])
            ->when($ignoreRawMarkId !== null, fn ($query) => $query->whereKeyNot($ignoreRawMarkId))
            ->where('event_at', '>=', $windowStart)
            ->where('event_at', '<', $windowEnd)
            ->orderBy('event_at')
            ->pluck('event_at')
            ->map(fn ($eventAt): CarbonImmutable => CarbonImmutable::parse($eventAt));

        if ($probe !== null
            && $probe->gte($windowStart)
            && $probe->lt($windowEnd)
            && ! $markInstants->contains(fn (CarbonImmutable $instant): bool => $instant->equalTo($probe))) {
            $markInstants->push($probe);
        }

        $leftMarks = $markInstants->filter(fn (CarbonImmutable $instant): bool => $instant->lt($boundary));
        $rightMarks = $markInstants->filter(fn (CarbonImmutable $instant): bool => $instant->gte($boundary));

        return $leftMarks->count() === 1 && $rightMarks->count() === 1
            ? $rightMarks->first()->addSecond()
            : $boundary;
    }

    private function defaultBoundaryBetween(
        Employee $employee,
        CarbonImmutable $leftDate,
        CarbonImmutable $rightDate,
    ): CarbonImmutable {
        [$leftStart, $scheduledEnd] = $this->assignedInterval($employee, $leftDate);
        [$rightStart, $rightEnd] = $this->assignedInterval($employee, $rightDate);

        return $this->defaultBoundary(
            $leftDate,
            $leftStart,
            $scheduledEnd,
            $rightDate,
            $rightStart,
            $rightEnd,
        );
    }

    private function defaultBoundary(
        CarbonImmutable $leftDate,
        ?CarbonImmutable $leftStart,
        ?CarbonImmutable $scheduledEnd,
        CarbonImmutable $rightDate,
        ?CarbonImmutable $rightStart,
        ?CarbonImmutable $rightEnd,
    ): CarbonImmutable {

        // Without a following shift, measure from the prior exit so a late exit remains a post-shift candidate.
        $leftAnchor = $leftDate->addHours(12);

        if ($leftStart !== null && $scheduledEnd !== null) {
            $leftAnchor = $rightStart === null
                ? $scheduledEnd
                : $this->midpoint($leftStart, $scheduledEnd);
        }

        $rightAnchor = $rightStart === null || $rightEnd === null
            ? $rightDate->addHours(12)
            : $this->midpoint($rightStart, $rightEnd);
        $boundary = $this->midpoint($leftAnchor, $rightAnchor);

        return $scheduledEnd !== null && $boundary->lte($scheduledEnd)
            ? $scheduledEnd->addSecond()
            : $boundary;
    }

    private function canBridgeOvernightPair(
        Employee $employee,
        ?CarbonImmutable $leftStart,
        ?CarbonImmutable $scheduledEnd,
        CarbonImmutable $rightDate,
        ?CarbonImmutable $rightStart,
        ?CarbonImmutable $rightEnd,
    ): bool {
        if ($leftStart === null
            || $scheduledEnd === null
            || $rightStart !== null
            || $rightEnd !== null
            || $leftStart->isSameDay($scheduledEnd)
            || ! $scheduledEnd->isSameDay($rightDate)) {
            return false;
        }

        $assignment = $this->assignmentFor($employee, $rightDate);
        $schedule = $assignment === null ? null : $this->scheduleFor($employee, $assignment, $rightDate);

        return $schedule !== null && ! $schedule->is_working_day;
    }

    /** @return array{CarbonImmutable|null, CarbonImmutable|null} */
    private function assignedInterval(Employee $employee, CarbonImmutable $date): array
    {
        $assignment = $this->assignmentFor($employee, $date);
        $schedule = $assignment === null ? null : $this->scheduleFor($employee, $assignment, $date);

        return $schedule === null ? [null, null] : $this->scheduledInterval($date, $schedule);
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
