<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeScheduleAssigner
{
    public function assign(
        Employee $employee,
        WorkScheduleProfile $profile,
        CarbonInterface|string $effectiveFrom,
        string $reason,
        ?User $actor = null,
    ): EmployeeScheduleAssignment {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'schedule_reason' => 'El motivo de la asignación es obligatorio.',
            ]);
        }

        if ($employee->company_id !== $profile->company_id) {
            throw ValidationException::withMessages([
                'schedule_profile_id' => 'La jornada debe pertenecer a la empresa del empleado.',
            ]);
        }

        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();

        return DB::transaction(function () use ($employee, $profile, $from, $reason, $actor): EmployeeScheduleAssignment {
            $lockedEmployee = Employee::withoutCompanyScope()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($lockedEmployee->company_id !== $profile->company_id) {
                throw ValidationException::withMessages([
                    'schedule_profile_id' => 'La jornada debe pertenecer a la empresa del empleado.',
                ]);
            }

            $assignments = EmployeeScheduleAssignment::withoutCompanyScope()
                ->where('employee_id', $employee->id)
                ->orderBy('effective_from')
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
            // Adjacent schedules define the midpoint boundaries that partition overnight marks.
            $affectedFrom = $from->subDay();
            $affectedTo = $effectiveTo?->addDay();
            $affectedPeriods = PayPeriod::withoutCompanyScope()
                ->withTrashed()
                ->where('company_id', $lockedEmployee->company_id)
                ->whereDate('end_date', '>=', $affectedFrom->toDateString())
                ->when($affectedTo !== null, fn ($query) => $query->whereDate('start_date', '<=', $affectedTo->toDateString()))
                ->lockForUpdate()
                ->get(['id', 'status']);

            if ($affectedPeriods->contains(fn (PayPeriod $period): bool => in_array(
                $period->status,
                PayPeriod::ATTENDANCE_LOCKED_STATUSES,
                true,
            ))) {
                throw ValidationException::withMessages([
                    'schedule_effective_from' => 'La jornada no puede cambiar fechas cubiertas por un período de nómina bloqueado.',
                ]);
            }

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
        });
    }
}
