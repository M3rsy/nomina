<?php

namespace App\Services\Attendance;

use App\Models\AttendanceFactGeneration;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfilePublication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class ScheduleResolutionSnapshot
{
    private Collection $assignmentsByEmployee;

    private Collection $schedulesByProfileAndDay;

    private Collection $publicationsByProfile;

    private Collection $factGenerationsByEmployee;

    private function __construct(
        Collection $assignments,
        Collection $schedules,
        Collection $publications,
        Collection $factGenerations,
    ) {
        $this->assignmentsByEmployee = $assignments->groupBy('employee_id');
        $this->schedulesByProfileAndDay = $schedules->groupBy(
            fn (WorkSchedule $schedule): string => $this->profileDayKey(
                $schedule->work_schedule_profile_id,
                $schedule->day_of_week,
            ),
        );
        $this->publicationsByProfile = $publications->groupBy('profile_id');
        $this->factGenerationsByEmployee = $factGenerations->groupBy('employee_id');
    }

    /** @param Collection<int, Employee> $employees */
    public static function capture(
        int $companyId,
        Collection $employees,
        CarbonInterface|string $start,
        CarbonInterface|string $end,
    ): self {
        $rangeStart = CarbonImmutable::parse($start)->startOfDay();
        $rangeEnd = CarbonImmutable::parse($end)->startOfDay();
        $assignments = EmployeeScheduleAssignment::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereDate('effective_from', '<=', $rangeEnd->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $rangeStart->toDateString()))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
        $profileIds = $assignments->pluck('work_schedule_profile_id')->unique();
        $schedules = WorkSchedule::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereIn('work_schedule_profile_id', $profileIds)
            ->get();
        $publications = WorkScheduleProfilePublication::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereIn('profile_id', $profileIds)
            ->whereDate('effective_from', '<=', $rangeEnd->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')
                ->orWhereDate('effective_to', '>', $rangeStart->toDateString()))
            ->orderBy('id')
            ->get();
        $factGenerations = AttendanceFactGeneration::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereBetween('work_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get();

        return new self($assignments, $schedules, $publications, $factGenerations);
    }

    /** @return Collection<int, EmployeeScheduleAssignment> */
    public function assignments(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->assignmentsByEmployee->get($employee->id, collect())
            ->filter(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_from->lte($date)
                && ($assignment->effective_to === null || $assignment->effective_to->gte($date)))
            ->values();
    }

    /** @return Collection<int, WorkScheduleProfilePublication> */
    public function publications(EmployeeScheduleAssignment $assignment, CarbonImmutable $date): Collection
    {
        return $this->publicationsByProfile->get($assignment->work_schedule_profile_id, collect())
            ->where('company_id', $assignment->company_id)
            ->filter(fn (WorkScheduleProfilePublication $publication): bool => $publication->effective_from->lte($date)
                && ($publication->effective_to === null || $publication->effective_to->gt($date)))
            ->values();
    }

    public function schedule(EmployeeScheduleAssignment $assignment, CarbonImmutable $date): ?WorkSchedule
    {
        return $this->schedulesByProfileAndDay->get(
            $this->profileDayKey($assignment->work_schedule_profile_id, $date->dayOfWeek),
            collect(),
        )->first();
    }

    /** @param iterable<CarbonInterface|string> $dates */
    public function factGeneration(Employee $employee, iterable $dates): int
    {
        $dateKeys = Collection::make($dates)
            ->map(fn (CarbonInterface|string $date): string => CarbonImmutable::parse($date)->toDateString())
            ->unique();

        return (int) $this->factGenerationsByEmployee->get($employee->id, collect())
            ->filter(fn (AttendanceFactGeneration $generation): bool => $dateKeys->contains(
                $generation->work_date->toDateString(),
            ))
            ->sum('generation');
    }

    private function profileDayKey(int $profileId, int $dayOfWeek): string
    {
        return $profileId.'|'.$dayOfWeek;
    }
}
