<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Payroll\LockedPayrollContext;
use App\Services\Payroll\PayrollContextLocker;
use App\Services\Payroll\PayrollContextTargets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Validation\ValidationException;

class EmployeeScheduleAssigner
{
    public function __construct(
        private PayrollContextLocker $contextLocker,
        private GeneralWorkScheduleResolver $generalResolver,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createAndAssignGeneral(
        array $attributes,
        CarbonInterface|string $effectiveFrom,
        string $reason,
        ?User $actor = null,
    ): EmployeeScheduleAssignment {
        $companyId = (int) ($attributes['company_id'] ?? 0);

        return $this->createAndAssign(
            $attributes,
            $this->generalResolver->resolve($companyId, $effectiveFrom),
            $effectiveFrom,
            $reason,
            $actor,
            true,
        );
    }

    /** @param array<string, mixed> $attributes */
    public function createAndAssign(
        array $attributes,
        WorkScheduleProfile $profile,
        CarbonInterface|string $effectiveFrom,
        string $reason,
        ?User $actor = null,
        bool $allowHistoricalProfile = false,
    ): EmployeeScheduleAssignment {
        $companyId = (int) ($attributes['company_id'] ?? 0);
        $reason = $this->validateReason($reason);
        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();

        return $this->contextLocker->within(
            $companyId,
            fn (): PayrollContextTargets => new PayrollContextTargets(
                payPeriodIds: $this->affectedPeriodIds($companyId, null, $from),
                profileIds: [$profile->id],
            ),
            function (LockedPayrollContext $context) use ($attributes, $profile, $companyId, $from, $reason, $actor, $allowHistoricalProfile): EmployeeScheduleAssignment {
                $lockedProfile = $this->lockedProfile($companyId, $context->profile($profile->id), $from, $allowHistoricalProfile);
                $employee = Employee::withoutCompanyScope()->create($attributes);

                return $this->storeAssignment($context, $employee, $lockedProfile, $from, $reason, $actor);
            },
        );
    }

    public function assign(
        Employee $employee,
        WorkScheduleProfile $profile,
        CarbonInterface|string $effectiveFrom,
        string $reason,
        ?User $actor = null,
        ?Closure $mutateEmployee = null,
        bool $allowHistoricalProfile = false,
    ): EmployeeScheduleAssignment {
        $reason = $this->validateReason($reason);
        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();

        return $this->contextLocker->within(
            $employee->company_id,
            fn (): PayrollContextTargets => new PayrollContextTargets(
                payPeriodIds: $this->affectedPeriodIds($employee->company_id, $employee->id, $from),
                employeeIds: [$employee->id],
                profileIds: [$profile->id],
                assignmentIds: $this->assignmentIds($employee->id),
            ),
            function (LockedPayrollContext $context) use ($employee, $profile, $from, $reason, $actor, $mutateEmployee, $allowHistoricalProfile): EmployeeScheduleAssignment {
                $lockedProfile = $this->lockedProfile($employee->company_id, $context->profile($profile->id), $from, $allowHistoricalProfile);
                $lockedEmployee = $context->employee($employee->id);

                return $this->storeAssignment(
                    $context,
                    $lockedEmployee,
                    $lockedProfile,
                    $from,
                    $reason,
                    $actor,
                    $mutateEmployee,
                );
            },
        );
    }

    public function assignLocked(
        LockedPayrollContext $context,
        Employee $employee,
        WorkScheduleProfile $profile,
        CarbonInterface|string $effectiveFrom,
        string $reason,
        ?User $actor = null,
    ): EmployeeScheduleAssignment {
        return $this->storeAssignment(
            $context,
            $employee,
            $profile,
            CarbonImmutable::parse($effectiveFrom)->startOfDay(),
            $this->validateReason($reason),
            $actor,
        );
    }

    private function storeAssignment(
        LockedPayrollContext $context,
        Employee $employee,
        WorkScheduleProfile $profile,
        CarbonImmutable $from,
        string $reason,
        ?User $actor,
        ?Closure $mutateEmployee = null,
    ): EmployeeScheduleAssignment {
        if ($employee->company_id !== $profile->company_id) {
            throw ValidationException::withMessages([
                'schedule_profile_id' => 'La jornada debe pertenecer a la empresa del empleado.',
            ]);
        }

        $assignments = $context->assignments
            ->where('employee_id', $employee->id)
            ->sortBy([['effective_from', 'asc'], ['id', 'asc']])
            ->values();

        if ($assignments->contains(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_from->isSameDay($from))) {
            throw ValidationException::withMessages([
                'schedule_effective_from' => 'Ya existe una jornada asignada desde esa fecha.',
            ]);
        }

        $previous = $assignments->last(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_from->lt($from));
        $next = $assignments->first(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_from->gt($from));
        $effectiveTo = $next === null
            ? null
            : CarbonImmutable::instance($next->effective_from)->subDay();
        if ($context->payPeriods->contains(fn (PayPeriod $period): bool => in_array(
            $period->status,
            PayPeriod::ATTENDANCE_LOCKED_STATUSES,
            true,
        ))) {
            throw ValidationException::withMessages([
                'schedule_effective_from' => 'La jornada no puede cambiar fechas cubiertas por un período de nómina bloqueado.',
            ]);
        }

        $mutateEmployee?->__invoke($employee);

        if ($previous !== null && ($previous->effective_to === null || $previous->effective_to->gte($from))) {
            $previous->update(['effective_to' => $from->subDay()->toDateString()]);
        }

        return EmployeeScheduleAssignment::withoutCompanyScope()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'work_schedule_profile_id' => $profile->id,
            'effective_from' => $from->toDateString(),
            'effective_to' => $effectiveTo?->toDateString(),
            'assigned_by' => $actor?->id,
            'reason' => $reason,
        ]);
    }

    /** @return list<int> */
    private function affectedPeriodIds(int $companyId, ?int $employeeId, CarbonImmutable $from): array
    {
        $nextDate = $employeeId === null ? null : EmployeeScheduleAssignment::withoutCompanyScope()
            ->where('employee_id', $employeeId)
            ->whereDate('effective_from', '>', $from->toDateString())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->value('effective_from');

        // Adjacent schedules define the midpoint boundaries that partition overnight marks.
        return PayPeriod::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->whereDate('end_date', '>=', $from->subDay()->toDateString())
            ->when($nextDate !== null, fn ($query) => $query->whereDate('start_date', '<=', $nextDate))
            ->pluck('id')
            ->all();
    }

    private function availableProfile(int $companyId, WorkScheduleProfile $profile): WorkScheduleProfile
    {
        if ($companyId !== $profile->company_id) {
            throw ValidationException::withMessages([
                'schedule_profile_id' => 'La jornada debe pertenecer a la empresa del empleado.',
            ]);
        }

        if (! $profile->is_active || $profile->retired_at !== null) {
            throw ValidationException::withMessages([
                'schedule_profile_id' => 'La jornada seleccionada ya no está disponible.',
            ]);
        }

        return $profile;
    }

    private function lockedProfile(
        int $companyId,
        WorkScheduleProfile $profile,
        CarbonImmutable $from,
        bool $allowHistoricalProfile,
    ): WorkScheduleProfile {
        if (! $allowHistoricalProfile) {
            return $this->availableProfile($companyId, $profile);
        }

        if ($this->generalResolver->resolve($companyId, $from)->id !== $profile->id) {
            throw ValidationException::withMessages([
                'schedule_profile_id' => 'La jornada general vigente cambió durante la asignación.',
            ]);
        }

        return $profile;
    }

    /** @return list<int> */
    private function assignmentIds(int $employeeId): array
    {
        return EmployeeScheduleAssignment::withoutCompanyScope()
            ->where('employee_id', $employeeId)
            ->pluck('id')
            ->all();
    }

    private function validateReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'schedule_reason' => 'El motivo de la asignación es obligatorio.',
            ]);
        }

        return $reason;
    }
}
