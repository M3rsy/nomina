<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfilePublication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AttendanceDecisionIdentityCompatibility
{
    public function __construct(private AttendanceShiftAnalyzer $analyzer) {}

    public function apply(
        Employee $employee,
        ShiftOccurrence $occurrence,
        AttendanceShiftAnalysis $analysis,
        bool $isHoliday,
        int $calendarGeneration,
        ?PayrollPeriodSnapshotData $snapshot = null,
    ): AttendanceShiftAnalysis {
        $previousOccurrence = $this->previousOccurrence($employee, $occurrence, $snapshot);
        if ($previousOccurrence === null) {
            return $analysis;
        }

        $previous = $this->analyzer->analyze($previousOccurrence, $isHoliday, $calendarGeneration);
        if ($previous->status !== $analysis->status
            || $previous->workedMinutes !== $analysis->workedMinutes
            || ! $this->sameBoundary($previous->entryAt, $analysis->entryAt)
            || ! $this->sameBoundary($previous->exitAt, $analysis->exitAt)) {
            return $analysis;
        }

        $overtime = $analysis->overtimeCandidates->map(function (AttendanceSegment $current) use ($previous): AttendanceSegment {
            $matching = $previous->overtimeCandidates->filter(
                fn (AttendanceSegment $legacy): bool => $this->equivalentOvertime($legacy, $current),
            )->values();

            return $matching->count() === 1
                ? $current->withCompatibleIdentities($matching->sole()->identities())
                : $current;
        });
        $deficits = $analysis->deficits->map(function (AttendanceSegment $current) use ($previous): AttendanceSegment {
            $matching = $previous->deficits->filter(
                fn (AttendanceSegment $legacy): bool => $this->equivalentDeficit($legacy, $current, $previous->deficits),
            )->values();

            return $matching->count() === 1
                ? $current->withCompatibleIdentities($matching->sole()->identities())
                : $current;
        });

        return $analysis->withDecisionSegments($deficits, $overtime);
    }

    private function previousOccurrence(
        Employee $employee,
        ShiftOccurrence $current,
        ?PayrollPeriodSnapshotData $snapshot,
    ): ?ShiftOccurrence {
        $date = $current->workDate;
        if ($current->payrollPolicyKey !== WorkScheduleProfilePublication::DURATION_FIRST_V2
            || $current->assignment === null
            || ! $current->assignment->effective_from->isSameDay($date)
            || $current->publicationId === null) {
            return null;
        }

        $publication = $snapshot?->publication($current->publicationId)
            ?? WorkScheduleProfilePublication::withoutCompanyScope()
                ->where('company_id', $employee->company_id)
                ->find($current->publicationId);
        if ($publication === null
            || $publication->payroll_policy_key !== WorkScheduleProfilePublication::DURATION_FIRST_V2
            || ! $publication->effective_from->isSameDay($date)) {
            return null;
        }

        $assignments = $snapshot?->assignmentsEnding($employee, $date->subDay())
            ?? EmployeeScheduleAssignment::withoutCompanyScope()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->whereDate('effective_to', $date->subDay()->toDateString())
                ->orderBy('id')
                ->get();
        if ($assignments->count() !== 1) {
            return null;
        }
        $assignment = $assignments->sole();
        $publications = $snapshot?->publicationsEnding($assignment, $date)
            ?? WorkScheduleProfilePublication::withoutCompanyScope()
                ->where('company_id', $employee->company_id)
                ->where('profile_id', $assignment->work_schedule_profile_id)
                ->where('payroll_policy_key', WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1)
                ->whereDate('effective_to', $date->toDateString())
                ->orderBy('id')
                ->get();
        if ($publications->count() !== 1) {
            return null;
        }

        $schedule = $snapshot?->schedule($assignment, $date)
            ?? WorkSchedule::withoutCompanyScope()
                ->where('company_id', $employee->company_id)
                ->where('work_schedule_profile_id', $assignment->work_schedule_profile_id)
                ->where('day_of_week', $date->dayOfWeek)
                ->first();
        if ($schedule === null) {
            return null;
        }
        [$start, $end] = $this->scheduledInterval($date, $schedule);

        return new ShiftOccurrence(
            workDate: $date,
            assignment: $assignment,
            schedule: $schedule,
            scheduledStart: $start,
            scheduledEnd: $end,
            marks: $current->marks,
            status: $current->status,
            factGeneration: $current->factGeneration,
            publicationId: $publications->sole()->id,
            payrollPolicyKey: WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1,
        );
    }

    private function equivalentOvertime(AttendanceSegment $legacy, AttendanceSegment $current): bool
    {
        return $legacy->kind === 'post_shift'
            && $current->kind === 'post_quota_overtime'
            && $legacy->minutes === $current->minutes
            && $this->sameBoundary($legacy->start, $current->start)
            && $this->sameBoundary($legacy->end, $current->end)
            && $legacy->rateMinutes == $current->rateMinutes;
    }

    /** @param Collection<int, AttendanceSegment> $legacyDeficits */
    private function equivalentDeficit(
        AttendanceSegment $legacy,
        AttendanceSegment $current,
        Collection $legacyDeficits,
    ): bool {
        return $legacyDeficits->count() === 1
            && in_array($legacy->kind, ['late_arrival', 'early_departure', 'full_day_absence'], true)
            && $current->kind === 'daily_shortfall'
            && $legacy->minutes === $current->minutes
            && $legacy->rateMinutes == $current->rateMinutes;
    }

    private function sameBoundary(?CarbonImmutable $left, ?CarbonImmutable $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null && $left->equalTo($right));
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
}
