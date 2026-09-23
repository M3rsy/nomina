<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EmployeePositionAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeePositionAssigner
{
    public function assign(
        Employee $employee,
        string $title,
        CarbonInterface|string $effectiveFrom,
        string $reason,
        ?User $actor = null,
    ): EmployeePositionAssignment {
        $title = trim($title);
        $reason = trim($reason);
        $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();

        if ($title === '') {
            throw ValidationException::withMessages([
                'job_title' => 'El nuevo cargo es obligatorio.',
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([
                'position_reason' => 'El motivo del cambio de cargo es obligatorio.',
            ]);
        }

        return DB::transaction(function () use ($employee, $title, $from, $reason, $actor): EmployeePositionAssignment {
            $lockedEmployee = Employee::withoutCompanyScope()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $assignments = EmployeePositionAssignment::withoutCompanyScope()
                ->where('employee_id', $lockedEmployee->id)
                ->lockForUpdate()
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get();

            if ($assignments->contains(
                fn (EmployeePositionAssignment $assignment): bool => $assignment->effective_from->isSameDay($from),
            )) {
                throw ValidationException::withMessages([
                    'position_effective_from' => 'Ya existe un cargo asignado desde esa fecha.',
                ]);
            }

            $previous = $assignments->last(
                fn (EmployeePositionAssignment $assignment): bool => $assignment->effective_from->lt($from),
            );
            $next = $assignments->first(
                fn (EmployeePositionAssignment $assignment): bool => $assignment->effective_from->gt($from),
            );

            if ($previous !== null && ($previous->effective_to === null || $previous->effective_to->gte($from))) {
                $previous->update(['effective_to' => $from->subDay()->toDateString()]);
            }

            $assignment = EmployeePositionAssignment::withoutCompanyScope()->create([
                'company_id' => $lockedEmployee->company_id,
                'employee_id' => $lockedEmployee->id,
                'title' => $title,
                'effective_from' => $from->toDateString(),
                'effective_to' => $next?->effective_from->copy()->subDay()->toDateString(),
                'assigned_by' => $actor?->id,
                'reason' => $reason,
            ]);

            $today = CarbonImmutable::today();

            if ($from->lte($today) && ($next === null || $next->effective_from->gt($today))) {
                $lockedEmployee->update(['job_title' => $title]);
            }

            return $assignment;
        });
    }
}
