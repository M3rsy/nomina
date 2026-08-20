<?php

namespace App\Services\Attendance;

use App\Models\Employee;

final class AttendanceShiftAnalysisResolver
{
    public function __construct(
        private AttendanceShiftAnalyzer $analyzer,
        private AttendanceDecisionIdentityCompatibility $identityCompatibility,
    ) {}

    public function resolve(
        Employee $employee,
        ShiftOccurrence $occurrence,
        HolidayCalendarContext $calendar,
        ?PayrollPeriodSnapshotData $snapshot = null,
    ): AttendanceShiftAnalysis {
        $date = $occurrence->workDate;
        $isHoliday = $calendar->isHoliday($date);
        $calendarGeneration = $calendar->generation($date);

        return $this->identityCompatibility->apply(
            $employee,
            $occurrence,
            $this->analyzer->analyze($occurrence, $isHoliday, $calendarGeneration),
            $isHoliday,
            $calendarGeneration,
            $snapshot,
        );
    }
}
