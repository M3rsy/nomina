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
    public function __construct(
        private Collection $assignments,
        private Collection $schedules,
        private Collection $publications,
        private Collection $marks,
        private Collection $factGenerations,
        private Collection $decisions,
        private Collection $exceptions,
        private Collection $variationAcknowledgements,
    ) {}

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
        return $this->assignments
            ->where('employee_id', $employee->id)
            ->filter(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_from->lte($date)
                && ($assignment->effective_to === null || $assignment->effective_to->gte($date)))
            ->values();
    }

    public function schedule(EmployeeScheduleAssignment $assignment, CarbonImmutable $date): ?WorkSchedule
    {
        return $this->schedules->first(fn (WorkSchedule $schedule): bool => $schedule->work_schedule_profile_id === $assignment->work_schedule_profile_id
            && $schedule->day_of_week === $date->dayOfWeek
        );
    }

    /** @return Collection<int, WorkScheduleProfilePublication> */
    public function publications(EmployeeScheduleAssignment $assignment, CarbonImmutable $date): Collection
    {
        return $this->publications
            ->where('company_id', $assignment->company_id)
            ->where('profile_id', $assignment->work_schedule_profile_id)
            ->filter(fn (WorkScheduleProfilePublication $publication): bool => $publication->effective_from->lte($date)
                && ($publication->effective_to === null || $publication->effective_to->gt($date)))
            ->values();
    }

    public function publication(int $id): ?WorkScheduleProfilePublication
    {
        return $this->publications->firstWhere('id', $id);
    }

    /** @return Collection<int, EmployeeScheduleAssignment> */
    public function assignmentsEnding(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->assignments
            ->where('employee_id', $employee->id)
            ->filter(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_to?->isSameDay($date) === true)
            ->values();
    }

    /** @return Collection<int, WorkScheduleProfilePublication> */
    public function publicationsEnding(EmployeeScheduleAssignment $assignment, CarbonImmutable $date): Collection
    {
        return $this->publications
            ->where('company_id', $assignment->company_id)
            ->where('profile_id', $assignment->work_schedule_profile_id)
            ->where('payroll_policy_key', WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1)
            ->filter(fn (WorkScheduleProfilePublication $publication): bool => $publication->effective_to?->isSameDay($date) === true)
            ->values();
    }

    /** @return Collection<int, RawMark> */
    public function marks(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->marks
            ->where('employee_id', $employee->id)
            ->filter(fn (RawMark $mark): bool => $mark->event_at->gte($start) && $mark->event_at->lt($end))
            ->values();
    }

    /** @param iterable<CarbonInterface|string> $dates */
    public function factGeneration(Employee $employee, iterable $dates): int
    {
        $keys = Collection::make($dates)
            ->map(fn (CarbonInterface|string $date): string => CarbonImmutable::parse($date)->toDateString());

        return (int) $this->factGenerations
            ->where('employee_id', $employee->id)
            ->filter(fn (AttendanceFactGeneration $generation): bool => $keys->contains($generation->work_date->toDateString())
            )
            ->sum('generation');
    }

    public function decisions(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->decisions->where('employee_id', $employee->id)
            ->filter(fn (OvertimeDecision $decision): bool => $decision->work_date->isSameDay($date))
            ->values();
    }

    public function exceptions(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->exceptions->where('employee_id', $employee->id)
            ->filter(fn (AttendanceException $exception): bool => $exception->work_date->isSameDay($date))
            ->values();
    }

    public function variationAcknowledgements(Employee $employee, CarbonImmutable $date): Collection
    {
        return $this->variationAcknowledgements->where('employee_id', $employee->id)
            ->filter(fn (AttendanceVariationAcknowledgement $acknowledgement): bool => $acknowledgement->work_date->isSameDay($date))
            ->values();
    }
}
