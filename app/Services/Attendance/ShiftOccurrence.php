<?php

namespace App\Services\Attendance;

use App\Models\EmployeeScheduleAssignment;
use App\Models\RawMark;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ShiftOccurrence
{
    public const RESOLVED = 'resolved';

    public const NO_MARKS = 'no_marks';

    public const MISSING_PAIR = 'missing_pair';

    public const AMBIGUOUS = 'ambiguous';

    public const MISSING_ASSIGNMENT = 'missing_assignment';

    public const MISSING_SCHEDULE = 'missing_schedule';

    /**
     * @param  Collection<int, RawMark>  $marks
     */
    public function __construct(
        public readonly CarbonImmutable $workDate,
        public readonly ?EmployeeScheduleAssignment $assignment,
        public readonly ?WorkSchedule $schedule,
        public readonly ?CarbonImmutable $scheduledStart,
        public readonly ?CarbonImmutable $scheduledEnd,
        public readonly Collection $marks,
        public readonly string $status,
        public readonly int $factGeneration = 0,
    ) {}

    public function entryMark(): ?RawMark
    {
        return $this->status === self::RESOLVED ? $this->marks->first() : null;
    }

    public function exitMark(): ?RawMark
    {
        return $this->status === self::RESOLVED ? $this->marks->last() : null;
    }
}
