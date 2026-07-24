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
    public function __construct(private PayrollContextLocker $contextLocker) {}

    /** @param array<string, mixed> $attributes */
    public function createAndAssign(
        array $attributes,
        WorkScheduleProfile $profile,
        CarbonInterface|string $effectiveFrom,
        string $reason,
        ?User $actor = null,
    ): EmployeeScheduleAssignment {
        $companyId = (int) ($attributes['company_id'] ?? 0);
        $reason = $this->validateRequest($companyId, $profile, $reason);
        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();

        return $this->contextLocker->within(
            $companyId,
            fn (): PayrollContextTargets => new PayrollContextTargets(
                payPeriodIds: $this->affectedPeriodIds($companyId, null, $from),
            ),
            function (LockedPayrollContext $context) use ($attributes, $profile, $from, $reason, $actor): EmployeeScheduleAssignment {
                $employee = Employee::withoutCompanyScope()->create($attributes);

                return $this->storeAssignment($context, $employee, $profile, $from, $reason, $actor);
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
    ): EmployeeScheduleAssignment {
        $reason = $this->validateRequest($employee->company_id, $profile, $reason);
        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();

        return $this->contextLocker->within(
            $employee->company_id,
            fn (): PayrollContextTargets => new PayrollContextTargets(
                payPeriodIds: $this->affectedPeriodIds($employee->company_id, $employee->id, $from),
                employeeIds: [$employee->id],
            ),
            function (LockedPayrollContext $context) use ($employee, $profile, $from, $reason, $actor, $mutateEmployee): EmployeeScheduleAssignment {
                $lockedEmployee = $context->employee($employee->id);

                return $this->storeAssignment(
                    $context,
                    $lockedEmployee,
                    $profile,
                    $from,
                    $reason,
                    $actor,
                    $mutateEmployee,
                );
            },
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

        $assignments = EmployeeScheduleAssignment::withoutCompanyScope()
            ->where('employee_id', $employee->id)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

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

    private function validateRequest(int $companyId, WorkScheduleProfile $profile, string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'schedule_reason' => 'El motivo de la asignación es obligatorio.',
            ]);
        }

        if ($companyId !== $profile->company_id) {
            throw ValidationException::withMessages([
                'schedule_profile_id' => 'La jornada debe pertenecer a la empresa del empleado.',
            ]);
        }

        return $reason;
    }
}
