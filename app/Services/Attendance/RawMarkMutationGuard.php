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
        return $this->mutateResolved(
            $rawMark->company_id,
            fn (): array => [$rawMark->id],
            $mutation,
            $targetEmployee,
            $targetEventAt,
        )->first();
    }

    public function mutateBatch(
        int $companyId,
        Closure $resolveRawMarkIds,
        Closure $mutation,
        ?Employee $targetEmployee = null,
    ): void {
        $this->mutateResolved($companyId, $resolveRawMarkIds, $mutation, $targetEmployee);
    }

    /** @return Collection<int, mixed> */
    private function mutateResolved(
        int $companyId,
        Closure $resolveRawMarkIds,
        Closure $mutation,
        ?Employee $targetEmployee,
        CarbonInterface|string|null $targetEventAt = null,
    ): Collection {
        return $this->contextLocker->within(
            $companyId,
            fn (): PayrollContextTargets => $this->resolveTargets(
                $companyId,
                $resolveRawMarkIds(),
                $targetEmployee,
                $targetEventAt,
            ),
            function (LockedPayrollContext $context) use ($mutation, $targetEmployee, $targetEventAt): Collection {
                $plans = $context->rawMarks->map(function (RawMark $lockedMark) use ($context, $targetEmployee, $targetEventAt): array {
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

                    return [
                        'mark' => $lockedMark,
                        'next_employee' => $nextEmployee,
                        'occurrences' => $this->affectedOccurrences(
                            $lockedMark,
                            $currentEmployee,
                            $nextEmployee,
                            $nextEventAt,
                        ),
                    ];
                });

                if ($context->payPeriods->contains(fn (PayPeriod $period): bool => in_array(
                    $period->status,
                    PayPeriod::ATTENDANCE_LOCKED_STATUSES,
                    true,
                ))) {
                    throw ValidationException::withMessages([
                        'raw_mark' => 'La marca pertenece a una fecha laboral de un período de nómina bloqueado.',
                    ]);
                }

                $generationAdvances = $plans->pluck('occurrences')->collapse()
                    ->groupBy(fn (array $occurrence): string => $this->occurrenceKey($occurrence))
                    ->sortKeys();
                $affectedOccurrences = $generationAdvances
                    ->map(fn (Collection $advances): array => $advances->first())
                    ->values();
                $results = $plans->map(fn (array $plan): mixed => $mutation($plan['mark']));

                foreach ($plans as $plan) {
                    $plan['mark']->refresh();
                    $this->assertManualPairIntegrity(
                        $affectedOccurrences,
                        $plan['mark'],
                        $plan['next_employee'],
                    );
                }

                $generationAdvances->each(function (Collection $advances): void {
                    $occurrence = $advances->first();

                    foreach ($advances as $_) {
                        $this->factGenerations->advance($occurrence['employee'], $occurrence['work_date']);
                    }
                });

                return $results;
            },
        );
    }

    private function resolveTargets(
        int $companyId,
        iterable $rawMarkIds,
        ?Employee $targetEmployee,
        CarbonInterface|string|null $targetEventAt,
    ): PayrollContextTargets {
        $rawMarkIds = collect($rawMarkIds)
            ->map(fn (mixed $target): int => (int) ($target instanceof RawMark ? $target->id : $target))
            ->unique()
            ->sort()
            ->values();
        $marks = RawMark::withoutCompanyScope()->whereIn('id', $rawMarkIds)->orderBy('id')->get();
        $employeeIds = $marks->pluck('employee_id')
            ->push($targetEmployee?->id)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $employees = Employee::withoutCompanyScope()->withTrashed()
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');
        $workDates = $marks->flatMap(function (RawMark $mark) use ($employees, $targetEmployee, $targetEventAt): Collection {
            $currentEmployee = $mark->employee_id === null ? null : $employees->get($mark->employee_id);
            $nextEmployee = $targetEmployee === null ? $currentEmployee : $employees->get($targetEmployee->id);
            $nextEventAt = $targetEventAt === null
                ? CarbonImmutable::instance($mark->event_at)
                : CarbonImmutable::parse($targetEventAt);

            return $this->affectedOccurrences($mark, $currentEmployee, $nextEmployee, $nextEventAt)
                ->pluck('work_date');
        })->map(fn (CarbonImmutable $date): string => $date->toDateString())
            ->unique()
            ->values();
        $payPeriodIds = $marks->isEmpty() ? [] : PayPeriod::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($marks, $workDates): void {
                $query->whereIn('id', $marks->pluck('pay_period_id'));

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
            rawMarkIds: $rawMarkIds->all(),
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
