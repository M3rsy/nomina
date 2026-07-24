<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Services\Payroll\LockedPayrollContext;
use App\Services\Payroll\PayrollContextLocker;
use App\Services\Payroll\PayrollContextTargets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RawMarkMutationGuard
{
    public function __construct(
        private ShiftOccurrenceResolver $resolver,
        private AttendanceFactGenerationTracker $factGenerations,
        private PayrollContextLocker $contextLocker,
    ) {}

    public function mutate(
        RawMark $rawMark,
        Closure $mutation,
        ?Employee $targetEmployee = null,
        CarbonInterface|string|null $targetEventAt = null,
    ): mixed {
        return $this->contextLocker->within(
            $rawMark->company_id,
            fn (): PayrollContextTargets => $this->resolveTargets(
                $rawMark->company_id,
                $rawMark->id,
                $targetEmployee,
                $targetEventAt,
            ),
            function (LockedPayrollContext $context) use ($rawMark, $mutation, $targetEmployee, $targetEventAt): mixed {
                $lockedMark = $context->rawMark($rawMark->id);
                $currentEmployee = $lockedMark->employee_id === null
                    ? null
                    : $context->employee($lockedMark->employee_id);
                $nextEmployee = $targetEmployee === null
                    ? $currentEmployee
                    : $context->employee($targetEmployee->id);

                if ($targetEmployee !== null
                    && ($nextEmployee->trashed() || $nextEmployee->company_id !== $lockedMark->company_id)) {
                    throw ValidationException::withMessages([
                        'raw_mark' => 'El empleado ya no admite cambios de marcas para esta empresa.',
                    ]);
                }

                $nextEventAt = $targetEventAt === null
                    ? CarbonImmutable::instance($lockedMark->event_at)
                    : CarbonImmutable::parse($targetEventAt);
                $affectedOccurrences = $this->affectedOccurrences(
                    $lockedMark,
                    $currentEmployee,
                    $nextEmployee,
                    $nextEventAt,
                );

                if ($context->payPeriods->contains(fn (PayPeriod $period): bool => in_array(
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

                foreach ($affectedOccurrences as $occurrence) {
                    $this->factGenerations->advance($occurrence['employee'], $occurrence['work_date']);
                }

                return $result;
            },
        );
    }

    private function resolveTargets(
        int $companyId,
        int $rawMarkId,
        ?Employee $targetEmployee,
        CarbonInterface|string|null $targetEventAt,
    ): PayrollContextTargets {
        $rawMark = RawMark::withoutCompanyScope()->whereKey($rawMarkId)->firstOrFail();
        $employeeIds = collect([$rawMark->employee_id, $targetEmployee?->id])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $employees = Employee::withoutCompanyScope()->withTrashed()
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');
        $currentEmployee = $rawMark->employee_id === null
            ? null
            : $employees->get($rawMark->employee_id);
        $nextEmployee = $targetEmployee === null
            ? $currentEmployee
            : $employees->get($targetEmployee->id);
        $nextEventAt = $targetEventAt === null
            ? CarbonImmutable::instance($rawMark->event_at)
            : CarbonImmutable::parse($targetEventAt);
        $workDates = $this->affectedOccurrences($rawMark, $currentEmployee, $nextEmployee, $nextEventAt)
            ->pluck('work_date')
            ->map(fn (CarbonImmutable $date): string => $date->toDateString())
            ->unique()
            ->values();
        $payPeriodIds = PayPeriod::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($rawMark, $workDates): void {
                $query->whereKey($rawMark->pay_period_id);

                foreach ($workDates as $workDate) {
                    $query->orWhere(function ($dateQuery) use ($workDate): void {
                        $dateQuery->whereDate('start_date', '<=', $workDate)
                            ->whereDate('end_date', '>=', $workDate);
                    });
                }
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return new PayrollContextTargets(
            payPeriodIds: $payPeriodIds,
            employeeIds: $employeeIds->all(),
            rawMarkIds: [$rawMarkId],
        );
    }

    /** @return Collection<int, array{employee: Employee, work_date: CarbonImmutable}> */
    private function affectedOccurrences(
        RawMark $rawMark,
        ?Employee $currentEmployee,
        ?Employee $nextEmployee,
        CarbonImmutable $nextEventAt,
    ): Collection {
        return collect([
            $currentEmployee === null ? null : [
                'employee' => $currentEmployee,
                'work_date' => $this->resolver->workDateFor($currentEmployee, $rawMark->event_at),
            ],
            $nextEmployee === null ? null : [
                'employee' => $nextEmployee,
                'work_date' => $this->resolver->workDateFor($nextEmployee, $nextEventAt, $rawMark->id),
            ],
        ])->filter()
            ->unique(fn (array $occurrence): string => $this->occurrenceKey($occurrence))
            ->sortBy(fn (array $occurrence): string => $this->occurrenceKey($occurrence))
            ->values();
    }

    /** @param  array{employee: Employee, work_date: CarbonImmutable}  $occurrence */
    private function occurrenceKey(array $occurrence): string
    {
        return sprintf('%020d|%s', $occurrence['employee']->id, $occurrence['work_date']->toDateString());
    }

    /** @param  Collection<int, array{employee: Employee, work_date: CarbonImmutable}>  $affectedOccurrences */
    private function assertManualPairIntegrity(
        Collection $affectedOccurrences,
        RawMark $mutatedMark,
        ?Employee $nextEmployee,
    ): void {
        foreach ($affectedOccurrences as $context) {
            $occurrence = $this->resolver->resolve($context['employee'], $context['work_date']);

            if (! $occurrence->satisfiesManualPairInvariant()) {
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
