<?php

namespace App\Services\Payroll;

use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\RawMarkMutationGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateEmployeeFromUnknownMark
{
    public function __construct(
        private EmployeeScheduleAssigner $scheduleAssigner,
        private RawMarkMutationGuard $markMutationGuard,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(
        RawMark $unknownMark,
        WorkScheduleProfile $profile,
        User $actor,
        array $attributes,
        string $reason,
        bool $assignAll = true,
    ): CreatedUnknownEmployee {
        $this->authorize($unknownMark, $actor);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['createEmployeeReason' => 'El motivo es obligatorio.']);
        }

        return DB::transaction(function () use ($unknownMark, $profile, $actor, $attributes, $reason, $assignAll): CreatedUnknownEmployee {
            $period = PayPeriod::withoutCompanyScope()->whereKey($unknownMark->pay_period_id)->firstOrFail();
            if (in_array($period->status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true)) {
                throw ValidationException::withMessages(['raw_mark' => 'El período ya no permite crear y asignar empleados.']);
            }

            $attributes = [
                ...$attributes,
                'company_id' => $period->company_id,
                'external_id' => $unknownMark->employee_external_id,
                'is_active' => true,
            ];
            $assignment = $this->scheduleAssigner->createAndAssign(
                $attributes,
                $profile,
                $attributes['hired_at'] ?? $period->start_date,
                $reason,
                $actor,
            );
            $employee = $assignment->employee()->firstOrFail();
            $assigned = 0;

            $this->markMutationGuard->mutateBatch(
                $period->company_id,
                fn (): array => RawMark::withoutCompanyScope()
                    ->where('company_id', $period->company_id)
                    ->where('pay_period_id', $period->id)
                    ->when(! $assignAll, fn ($query) => $query->whereKey($unknownMark->id))
                    ->when($assignAll, fn ($query) => $query
                        ->where('employee_external_id', $unknownMark->employee_external_id)
                        ->whereNull('employee_id'))
                    ->pluck('id')->all(),
                function (RawMark $mark) use ($employee, $actor, $reason, &$assigned): void {
                    $metadata = $mark->metadata ?? [];
                    $metadata['revisions'][] = [
                        'action' => 'create_and_assign_employee',
                        'user_id' => $actor->id,
                        'reason' => $reason,
                        'new_employee_id' => $employee->id,
                        'at' => now()->toDateTimeString(),
                    ];
                    $mark->update([
                        'employee_id' => $employee->id,
                        'status' => $mark->status === 'unknown_employee' ? 'corrected' : $mark->status,
                        'metadata' => $metadata,
                    ]);
                    $assigned++;
                },
                targetEmployee: $employee,
            );

            return new CreatedUnknownEmployee($employee, $assigned);
        });
    }

    private function authorize(RawMark $mark, User $actor): void
    {
        if (! $actor->is_active
            || ! $actor->can('employees.create')
            || ! $actor->can('marks.manage')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $mark->company_id)) {
            throw new AuthorizationException('Not authorized to create an employee from this mark.');
        }
    }
}
