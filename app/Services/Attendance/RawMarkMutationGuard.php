<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RawMarkMutationGuard
{
    public function __construct(private ShiftOccurrenceResolver $resolver) {}

    public function mutate(
        RawMark $rawMark,
        Closure $mutation,
        ?Employee $targetEmployee = null,
        CarbonInterface|string|null $targetEventAt = null,
    ): mixed {
        return DB::transaction(function () use ($rawMark, $mutation, $targetEmployee, $targetEventAt): mixed {
            $lockedMark = RawMark::withoutCompanyScope()
                ->whereKey($rawMark->id)
                ->lockForUpdate()
                ->firstOrFail();
            $employeeIds = array_values(array_unique(array_filter([
                $lockedMark->employee_id,
                $targetEmployee?->id,
            ])));
            $employees = Employee::withoutCompanyScope()
                ->withTrashed()
                ->whereIn('id', $employeeIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $currentEmployee = $lockedMark->employee_id === null
                ? null
                : $employees->get($lockedMark->employee_id);
            $nextEmployee = $targetEmployee === null
                ? $currentEmployee
                : $employees->get($targetEmployee->id);

            if ($targetEmployee !== null
                && ($nextEmployee === null || $nextEmployee->trashed() || $nextEmployee->company_id !== $lockedMark->company_id)) {
                throw ValidationException::withMessages([
                    'raw_mark' => 'El empleado ya no admite cambios de marcas para esta empresa.',
                ]);
            }

            $nextEventAt = $targetEventAt === null
                ? CarbonImmutable::instance($lockedMark->event_at)
                : CarbonImmutable::parse($targetEventAt);
            $affectedOccurrences = collect();

            if ($currentEmployee !== null) {
                $affectedOccurrences->push([
                    'employee' => $currentEmployee,
                    'work_date' => $this->resolver->workDateFor($currentEmployee, $lockedMark->event_at),
                ]);
            }

            if ($nextEmployee !== null) {
                $affectedOccurrences->push([
                    'employee' => $nextEmployee,
                    'work_date' => $this->resolver->workDateFor($nextEmployee, $nextEventAt),
                ]);
            }

            $affectedOccurrences = $affectedOccurrences
                ->unique(fn (array $context): string => $context['employee']->id.'|'.$context['work_date']->toDateString())
                ->values();
            $workDates = $affectedOccurrences
                ->pluck('work_date')
                ->map(fn (CarbonImmutable $date): string => $date->toDateString())
                ->unique()
                ->values();
            $periods = PayPeriod::withoutCompanyScope()
                ->withTrashed()
                ->where('company_id', $lockedMark->company_id)
                ->where(function ($query) use ($lockedMark, $workDates): void {
                    $query->whereKey($lockedMark->pay_period_id);

                    foreach ($workDates as $workDate) {
                        $query->orWhere(function ($dateQuery) use ($workDate): void {
                            $dateQuery->whereDate('start_date', '<=', $workDate)
                                ->whereDate('end_date', '>=', $workDate);
                        });
                    }
                })
                ->lockForUpdate()
                ->get(['id', 'status']);

            if ($periods->contains(fn (PayPeriod $period): bool => in_array(
                $period->status,
                PayPeriod::ATTENDANCE_LOCKED_STATUSES,
                true,
            ))) {
                throw ValidationException::withMessages([
                    'raw_mark' => 'La marca pertenece a una fecha laboral de un período de nómina bloqueado.',
                ]);
            }

            $result = $mutation($lockedMark);
            $lockedMark->refresh();

            $this->assertManualPairIntegrity($affectedOccurrences, $lockedMark, $nextEmployee);

            return $result;
        });
    }

    /** @param  Collection<int, array{employee: Employee, work_date: CarbonImmutable}>  $affectedOccurrences */
    private function assertManualPairIntegrity(
        Collection $affectedOccurrences,
        RawMark $mutatedMark,
        ?Employee $nextEmployee,
    ): void {
        foreach ($affectedOccurrences as $context) {
            $occurrence = $this->resolver->resolve($context['employee'], $context['work_date']);
            $manualCount = $occurrence->marks
                ->where('source', RawMark::SOURCE_MANUAL)
                ->count();

            if ($manualCount > 0
                && ($occurrence->status !== ShiftOccurrence::RESOLVED
                    || $occurrence->marks->count() !== 2
                    || $manualCount !== 1)) {
                $this->rejectInvalidManualPair();
            }
        }

        if ($mutatedMark->source !== RawMark::SOURCE_MANUAL
            || ! in_array($mutatedMark->status, ['valid', 'corrected'], true)) {
            return;
        }

        if ($nextEmployee === null) {
            $this->rejectInvalidManualPair();
        }

        $workDate = $this->resolver->workDateFor($nextEmployee, $mutatedMark->event_at);
        $occurrence = $this->resolver->resolve($nextEmployee, $workDate);

        if (! $occurrence->marks->contains('id', $mutatedMark->id)) {
            $this->rejectInvalidManualPair();
        }
    }

    private function rejectInvalidManualPair(): never
    {
        throw ValidationException::withMessages([
            'raw_mark' => 'Una marca manual auditada debe permanecer emparejada con una sola marca observada.',
        ]);
    }
}
