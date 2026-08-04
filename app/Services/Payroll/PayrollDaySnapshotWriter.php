<?php

namespace App\Services\Payroll;

use App\Models\PayrollResult;
use LogicException;

final class PayrollDaySnapshotWriter
{
    /** @param array<string, mixed> $attributes */
    public function write(array $attributes, PayrollDaySnapshot $snapshot): PayrollResult
    {
        $results = PayrollResult::withoutCompanyScope()->withoutGlobalScope('currentGeneration')
            ->where('company_id', $attributes['company_id'])
            ->where('pay_period_id', $attributes['pay_period_id'])
            ->where('employee_id', $attributes['employee_id'])
            ->whereDate('date', $attributes['date']);
        $generation = $attributes['result_generation'];
        $existing = (clone $results)->where('result_generation', $generation)->first();

        if ($existing !== null) {
            if (is_string($existing->snapshot_hash) && hash_equals($existing->snapshot_hash, $snapshot->hash)) {
                return $existing;
            }

            throw new LogicException('Conflicting immutable payroll result already exists.');
        }

        return PayrollResult::withoutCompanyScope()->create([
            ...$attributes,
            'supersedes_id' => $generation > 1
                ? (clone $results)->where('result_generation', $generation - 1)->value('id')
                : null,
            'day_snapshot' => $snapshot->data,
            'snapshot_hash' => $snapshot->hash,
        ]);
    }
}
