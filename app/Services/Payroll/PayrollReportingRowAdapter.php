<?php

namespace App\Services\Payroll;

use App\Models\PayrollResult;

final class PayrollReportingRowAdapter
{
    /** @return array<string, mixed> */
    public function adapt(PayrollResult $result): array
    {
        $snapshot = $result->day_snapshot;
        if (is_array($snapshot) && ($snapshot['schema_version'] ?? null) === 2) {
            return [
                ...$this->adaptSnapshot($snapshot),
                'employee_payment_code' => $result->employee_payment_code,
                'employee_job_title' => $result->employee_job_title,
            ];
        }

        $employee = ($result->employee_external_id === null || $result->employee_name === null)
            ? $result->employee()->withTrashed()->first()
            : null;

        return [
            'employee_external_id' => $result->employee_external_id ?? $employee?->external_id,
            'employee_name' => $result->employee_name ?? $employee?->full_name,
            'employee_payment_code' => $result->employee_payment_code ?? $employee?->payment_code,
            'employee_job_title' => $result->employee_job_title ?? $employee?->job_title,
            'work_date' => $result->date?->toDateString(),
            'status' => 'LEGACY',
            'entry_at' => $result->entry_at,
            'exit_at' => $result->exit_at,
            'worked_minutes' => $result->worked_minutes,
            'ordinary_minutes' => $result->ordinary_minutes,
            'extra_25_minutes' => $result->extra_25_minutes,
            'extra_50_minutes' => $result->extra_50_minutes,
            'extra_75_minutes' => $result->extra_75_minutes,
            'extra_100_minutes' => $result->extra_100_minutes,
            'observed_marks' => null,
            'mark_revisions' => null,
            'shortfall_minutes' => null,
            'shortfall_state' => null,
            'shortfall_reason' => null,
            'detected_overtime' => null,
            'approved_overtime' => null,
            'rejected_overtime' => null,
            'approved_overtime_minutes' => null,
            'variation' => null,
            'acknowledgement' => null,
            'excluded_transfer_minutes' => null,
            'rules_version' => $result->rules_version,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function adaptSnapshot(array $snapshot): array
    {
        $employee = $snapshot['employee'] ?? [];
        $attendance = $snapshot['attendance'] ?? [];
        $payable = $snapshot['payable_minutes'] ?? [];
        $marks = $attendance['marks'] ?? [];
        $shortfalls = $snapshot['shortfalls'] ?? [];
        $overtime = $snapshot['overtime'] ?? [];
        $variations = $snapshot['variations'] ?? [];

        $decisions = array_values(array_filter(array_column($overtime, 'decision')));
        $acknowledgements = array_values(array_filter(array_column($variations, 'acknowledgement')));

        return [
            'employee_external_id' => $employee['external_id'] ?? null,
            'employee_name' => $employee['name'] ?? null,
            'employee_payment_code' => null,
            'employee_job_title' => null,
            'work_date' => $snapshot['work_date'] ?? null,
            'status' => 'CURRENT',
            'entry_at' => $attendance['entry_at'] ?? null,
            'exit_at' => $attendance['exit_at'] ?? null,
            'worked_minutes' => $attendance['worked_minutes'] ?? null,
            'ordinary_minutes' => $payable['ordinary'] ?? null,
            'extra_25_minutes' => $payable['extra25'] ?? null,
            'extra_50_minutes' => $payable['extra50'] ?? null,
            'extra_75_minutes' => $payable['extra75'] ?? null,
            'extra_100_minutes' => $payable['extra100'] ?? null,
            'observed_marks' => $this->json($marks),
            'mark_revisions' => $this->json(array_map(fn (array $mark): array => [
                'mark_id' => $mark['id'] ?? null,
                'revisions' => $mark['revisions'] ?? [],
            ], $marks)),
            'shortfall_minutes' => array_sum(array_map(
                fn (array $shortfall): int => (int) ($shortfall['fact']['minutes'] ?? 0),
                $shortfalls,
            )),
            'shortfall_state' => $this->join($shortfalls, 'state'),
            'shortfall_reason' => $this->join($shortfalls, 'reason'),
            'detected_overtime' => $this->json(array_column($overtime, 'candidate')),
            'approved_overtime' => $this->json(array_map(fn (array $decision): array => $this->only($decision, [
                'approved_starts_at', 'approved_ends_at', 'approved_minutes', 'approved_rate_minutes',
            ]), $decisions)),
            'rejected_overtime' => $this->json(array_map(fn (array $decision): array => $this->only($decision, [
                'rejected_before_starts_at', 'rejected_before_ends_at', 'rejected_after_starts_at',
                'rejected_after_ends_at', 'rejected_minutes', 'rejected_before_minutes',
                'rejected_after_minutes', 'rejected_rate_minutes',
            ]), $decisions)),
            'approved_overtime_minutes' => $attendance['approved_overtime_minutes'] ?? null,
            'variation' => $this->json(array_map(function (array $variation): array {
                unset($variation['acknowledgement']);

                return $variation;
            }, $variations)),
            'acknowledgement' => $this->json($acknowledgements),
            'excluded_transfer_minutes' => $attendance['excluded_transfer_minutes'] ?? null,
            'rules_version' => $snapshot['rules_version'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function join(array $items, string $key): ?string
    {
        $values = array_values(array_filter(array_column($items, $key), fn ($value): bool => $value !== null && $value !== ''));

        return $values === [] ? null : implode('; ', $values);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param list<string> $keys */
    private function only(array $value, array $keys): array
    {
        return array_intersect_key($value, array_flip($keys));
    }
}
