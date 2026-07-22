<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
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
            $workDates = collect();

            if ($currentEmployee !== null) {
                $workDates->push($this->resolver->workDateFor($currentEmployee, $lockedMark->event_at));
            }

            if ($nextEmployee !== null) {
                $workDates->push($this->resolver->workDateFor($nextEmployee, $nextEventAt));
            }

            $workDates = $workDates
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

            return $mutation($lockedMark);
        });
    }
}
