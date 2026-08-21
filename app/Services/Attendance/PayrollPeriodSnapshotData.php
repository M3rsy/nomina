<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\AttendanceFactGeneration;
use App\Models\AttendanceVariationAcknowledgement;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfilePublication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class PayrollPeriodSnapshotData
{
    private Collection $assignmentsByEmployee;

    private Collection $schedulesByProfileAndDay;

    private Collection $publicationsByProfile;

    private Collection $publicationsById;

    private Collection $marksByEmployee;

    private Collection $factGenerationsByEmployee;

    private Collection $decisionsByEmployeeAndDate;

    private Collection $exceptionsByEmployeeAndDate;

    private Collection $variationAcknowledgementsByEmployeeAndDate;

    public function __construct(
        private Collection $assignments,
        private Collection $schedules,
        private Collection $publications,
        private Collection $marks,
        private Collection $factGenerations,
        private Collection $decisions,
        private Collection $exceptions,
        private Collection $variationAcknowledgements,
    ) {
        $this->assignmentsByEmployee = $assignments->groupBy('employee_id');
        $this->schedulesByProfileAndDay = $schedules->groupBy(
            fn (WorkSchedule $schedule): string => $this->profileDayKey(
                $schedule->work_schedule_profile_id,
                $schedule->day_of_week,
            ),
        );
        $this->publicationsByProfile = $publications->groupBy('profile_id');
        $this->publicationsById = $publications->keyBy('id');
        $this->marksByEmployee = $marks->groupBy('employee_id');
        $this->factGenerationsByEmployee = $factGenerations->groupBy('employee_id');
        $this->decisionsByEmployeeAndDate = $decisions->groupBy(
            fn (OvertimeDecision $decision): string => $this->employeeDateKey($decision->employee_id, $decision->work_date),
        );
        $this->exceptionsByEmployeeAndDate = $exceptions->groupBy(
            fn (AttendanceException $exception): string => $this->employeeDateKey($exception->employee_id, $exception->work_date),
        );
        $this->variationAcknowledgementsByEmployeeAndDate = $variationAcknowledgements->groupBy(
            fn (AttendanceVariationAcknowledgement $acknowledgement): string => $this->employeeDateKey($acknowledgement->employee_id, $acknowledgement->work_date),
        );
    }

    /** @param Collection<int, Employee> $employees */
    public static function capture(PayPeriod $period, Collection $employees): self
    {
        $employeeIds = $employees->modelKeys();
        $start = CarbonImmutable::parse($period->start_date)->subDays(2);
        $end = CarbonImmutable::parse($period->end_date)->addDays(2);
        $assignments = EmployeeScheduleAssignment::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->orderByDesc('effective_from')
            ->get();
        $schedules = WorkSchedule::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('work_schedule_profile_id', $assignments->pluck('work_schedule_profile_id')->unique())
            ->get();
        $publications = WorkScheduleProfilePublication::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('profile_id', $assignments->pluck('work_schedule_profile_id')->unique())
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>', $start))
            ->get();
        $marks = RawMark::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['valid', 'corrected'])
            ->where('event_at', '>=', $start->startOfDay())
            ->where('event_at', '<', $end->addDay()->startOfDay())
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();
        $factGenerations = AttendanceFactGeneration::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get();
        $decisions = OvertimeDecision::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->where('pay_period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', '>=', CarbonImmutable::parse($period->start_date)->toDateString())
            ->whereDate('work_date', '<=', CarbonImmutable::parse($period->end_date)->toDateString())
            ->current()
            ->with('decider')
            ->get();
        $exceptions = AttendanceException::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->where('pay_period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', '>=', CarbonImmutable::parse($period->start_date)->toDateString())
            ->whereDate('work_date', '<=', CarbonImmutable::parse($period->end_date)->toDateString())
            ->current()
            ->with('decider')
            ->get();
        $variationAcknowledgements = AttendanceVariationAcknowledgement::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->where('pay_period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', '>=', $period->start_date->toDateString())
            ->whereDate('work_date', '<=', $period->end_date->toDateString())
            ->with('acknowledger')
            ->get();

        return new self($assignments, $schedules, $publications, $marks, $factGenerations, $decisions, $exceptions, $variationAcknowledgements);
    }

    public function assignment(Employee $employee, CarbonImmutable $date): ?EmployeeScheduleAssignment
    {
        $assignments = $this->assignments($employee, $date);

        return $assignments->count() === 1 ? $assignments->sole() : null;
    }

    /** @return Collection<int, EmployeeScheduleAssignment> */
    public function assignments(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->assignmentsByEmployee->get($employee->id, collect())
            ->filter(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_from->lte($date)
                && ($assignment->effective_to === null || $assignment->effective_to->gte($date)))
            ->values();
    }

    public function schedule(EmployeeScheduleAssignment $assignment, CarbonImmutable $date): ?WorkSchedule
    {
        return $this->schedulesByProfileAndDay->get(
            $this->profileDayKey($assignment->work_schedule_profile_id, $date->dayOfWeek),
            collect(),
        )->first();
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

    public function publication(int $id): ?WorkScheduleProfilePublication
    {
        return $this->publicationsById->get($id);
    }

    /** @return Collection<int, EmployeeScheduleAssignment> */
    public function assignmentsEnding(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->assignmentsByEmployee->get($employee->id, collect())
            ->filter(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_to?->isSameDay($date) === true)
            ->values();
    }

    /** @return Collection<int, WorkScheduleProfilePublication> */
    public function publicationsEnding(EmployeeScheduleAssignment $assignment, CarbonImmutable $date): Collection
    {
        return $this->publicationsByProfile->get($assignment->work_schedule_profile_id, collect())
            ->where('company_id', $assignment->company_id)
            ->where('payroll_policy_key', WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1)
            ->filter(fn (WorkScheduleProfilePublication $publication): bool => $publication->effective_to?->isSameDay($date) === true)
            ->values();
    }

    /** @return Collection<int, RawMark> */
    public function marks(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->marksByEmployee->get($employee->id, collect())
            ->filter(fn (RawMark $mark): bool => $mark->event_at->gte($start) && $mark->event_at->lt($end))
            ->values();
    }

    /** @param iterable<CarbonInterface|string> $dates */
    public function factGeneration(Employee $employee, iterable $dates): int
    {
        $keys = Collection::make($dates)
            ->map(fn (CarbonInterface|string $date): string => CarbonImmutable::parse($date)->toDateString());

        return (int) $this->factGenerationsByEmployee->get($employee->id, collect())
            ->filter(fn (AttendanceFactGeneration $generation): bool => $keys->contains($generation->work_date->toDateString())
            )
            ->sum('generation');
    }

    public function decisions(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->decisionsByEmployeeAndDate
            ->get($this->employeeDateKey($employee->id, $date), collect())
            ->values();
    }

    public function exceptions(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->exceptionsByEmployeeAndDate
            ->get($this->employeeDateKey($employee->id, $date), collect())
            ->values();
    }

    public function variationAcknowledgements(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->variationAcknowledgementsByEmployeeAndDate
            ->get($this->employeeDateKey($employee->id, $date), collect())
            ->values();
    }

    private function profileDayKey(int $profileId, int $dayOfWeek): string
    {
        return $profileId.'|'.$dayOfWeek;
    }

    private function employeeDateKey(int $employeeId, CarbonInterface|string $date): string
    {
        return $employeeId.'|'.CarbonImmutable::parse($date)->toDateString();
    }
}
