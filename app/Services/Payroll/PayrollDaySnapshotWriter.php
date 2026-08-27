<?php

namespace App\Services\Payroll;

use App\Models\PayrollResult;
use LogicException;

final class PayrollDaySnapshotWriter
{
    /** @var array{company_id:int,pay_period_id:int,employee_ids:array<int, true>,start_date:string,end_date:string,generations:array<int, true>}|null */
    private ?array $preloadedScope = null;

    /** @var array<string, PayrollResult> */
    private array $preloadedResults = [];

    /**
     * @param  array<int, int>  $employeeIds
     */
    public function preloadForPayrollPeriod(
        int $companyId,
        int $payPeriodId,
        array $employeeIds,
        string $startDate,
        string $endDate,
        int $generation,
    ): void {
        $generations = array_values(array_filter([$generation, $generation - 1], fn (int $value): bool => $value > 0));
        $this->preloadedScope = [
            'company_id' => $companyId,
            'pay_period_id' => $payPeriodId,
            'employee_ids' => array_fill_keys($employeeIds, true),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'generations' => array_fill_keys($generations, true),
        ];
        $this->preloadedResults = [];

        if ($employeeIds === []) {
            return;
        }

        PayrollResult::withoutCompanyScope()->withoutGlobalScope('currentGeneration')
            ->where('company_id', $companyId)
            ->where('pay_period_id', $payPeriodId)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->whereIn('result_generation', $generations)
            ->get()
            ->each(fn (PayrollResult $result) => $this->preloadedResults[$this->key(
                $result->company_id,
                $result->pay_period_id,
                $result->employee_id,
                $result->date->toDateString(),
                $result->result_generation,
            )] = $result);
    }

    /** @param array<string, mixed> $attributes */
    public function write(array $attributes, PayrollDaySnapshot $snapshot): PayrollResult
    {
        $generation = $attributes['result_generation'];
        $existing = $this->existing($attributes, $generation);

        if ($existing !== null) {
            if (is_string($existing->snapshot_hash) && hash_equals($existing->snapshot_hash, $snapshot->hash)) {
                return $existing;
            }

            throw new LogicException('Conflicting immutable payroll result already exists.');
        }

        $stored = PayrollResult::withoutCompanyScope()->create([
            ...$attributes,
            'supersedes_id' => $generation > 1
                ? $this->existing($attributes, $generation - 1)?->id
                : null,
            'day_snapshot' => $snapshot->data,
            'snapshot_hash' => $snapshot->hash,
        ]);

        if ($this->isPreloaded($attributes, $generation)) {
            $this->preloadedResults[$this->keyFromAttributes($attributes, $generation)] = $stored;
        }

        return $stored;
    }

    /** @param array<string, mixed> $attributes */
    private function existing(array $attributes, int $generation): ?PayrollResult
    {
        if ($this->isPreloaded($attributes, $generation)) {
            return $this->preloadedResults[$this->keyFromAttributes($attributes, $generation)] ?? null;
        }

        return PayrollResult::withoutCompanyScope()->withoutGlobalScope('currentGeneration')
            ->where('company_id', $attributes['company_id'])
            ->where('pay_period_id', $attributes['pay_period_id'])
            ->where('employee_id', $attributes['employee_id'])
            ->whereDate('date', $attributes['date'])
            ->where('result_generation', $generation)
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    private function isPreloaded(array $attributes, int $generation): bool
    {
        $scope = $this->preloadedScope;
        $date = (string) $attributes['date'];

        return $scope !== null
            && $scope['company_id'] === $attributes['company_id']
            && $scope['pay_period_id'] === $attributes['pay_period_id']
            && isset($scope['employee_ids'][$attributes['employee_id']])
            && $date >= $scope['start_date']
            && $date <= $scope['end_date']
            && isset($scope['generations'][$generation]);
    }

    /** @param array<string, mixed> $attributes */
    private function keyFromAttributes(array $attributes, int $generation): string
    {
        return $this->key(
            $attributes['company_id'],
            $attributes['pay_period_id'],
            $attributes['employee_id'],
            (string) $attributes['date'],
            $generation,
        );
    }

    private function key(int $companyId, int $payPeriodId, int $employeeId, string $date, int $generation): string
    {
        return implode(':', [$companyId, $payPeriodId, $employeeId, $date, $generation]);
    }
}
