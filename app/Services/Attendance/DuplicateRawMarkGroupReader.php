<?php

namespace App\Services\Attendance;

use App\Models\PayPeriod;
use App\Models\RawMark;
use Illuminate\Support\Collection;

final class DuplicateRawMarkGroupReader
{
    /**
     * @return array{
     *     groups: Collection<int, array<string, mixed>>,
     *     duplicate_groups: int,
     *     duplicate_records_to_resolve: int,
     *     employee_count_with_duplicates: int,
     * }
     */
    public function forPeriod(PayPeriod $period, ?int $uploadedFileId = null): array
    {
        $periodMarks = $this->baseQuery($period)
            ->where('pay_period_id', $period->id)
            ->when($uploadedFileId !== null, fn ($query) => $query->where('uploaded_file_id', $uploadedFileId))
            ->get();
        $candidateKeys = $periodMarks
            ->map(fn (RawMark $mark): string => $this->identityKey($mark))
            ->unique()
            ->values();

        if ($candidateKeys->isEmpty()) {
            $groups = collect();
        } else {
            $marks = $this->baseQuery($period)
                ->with(['employee', 'uploadedFile'])
                ->orderBy('employee_external_id')
                ->orderBy('event_at')
                ->orderBy('id')
                ->get()
                ->filter(fn (RawMark $mark): bool => $candidateKeys->contains($this->identityKey($mark)))
                ->values();

            $groups = $marks
                ->groupBy(fn (RawMark $mark): string => $this->identityKey($mark))
                ->filter(fn (Collection $group): bool => $group->count() > 1)
                ->filter(fn (Collection $group): bool => $group->contains(fn (RawMark $mark): bool => $mark->pay_period_id === $period->id))
                ->filter(fn (Collection $group): bool => $uploadedFileId === null
                    || $group->contains(fn (RawMark $mark): bool => $mark->uploaded_file_id === $uploadedFileId))
                ->map(fn (Collection $group, string $key): array => $this->group($group, $key, $period))
                ->filter(fn (array $group): bool => count($group['removable_records']) > 0)
                ->values();
        }

        return [
            'groups' => $groups,
            'duplicate_groups' => $groups->count(),
            'duplicate_records_to_resolve' => $groups->sum(fn (array $group): int => count($group['removable_records'])),
            'employee_count_with_duplicates' => $groups
                ->pluck('employee_external_id')
                ->filter(fn (mixed $externalId): bool => is_string($externalId) && $externalId !== '')
                ->unique()
                ->count(),
        ];
    }

    public function identityKey(RawMark $mark): string
    {
        return $mark->employee_external_id.'|'.$mark->event_at->toDateTimeString();
    }

    private function baseQuery(PayPeriod $period)
    {
        return RawMark::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->where('status', '!=', 'deleted')
            ->where(function ($query): void {
                $query->whereNull('uploaded_file_id')
                    ->orWhereHas('uploadedFile');
            });
    }

    /** @return array<string, mixed> */
    private function group(Collection $marks, string $key, PayPeriod $period): array
    {
        $ordered = $marks->sortBy(fn (RawMark $mark): string => sprintf(
            '%d|%d|%020d',
            in_array($mark->status, ['corrected', 'valid'], true) ? 0 : 1,
            $mark->pay_period_id === $period->id ? 0 : 1,
            $mark->id,
        ))->values();
        $kept = $ordered->first();

        return [
            'key' => $key,
            'employee_name' => $kept?->employee?->full_name,
            'employee_external_id' => $kept?->employee?->external_id ?? $kept?->employee_external_id,
            'event_at' => $kept?->event_at?->toDateTimeString(),
            'total_records' => $marks->count(),
            'kept_candidate' => $this->details($kept, true),
            'removable_records' => $ordered
                ->skip(1)
                ->filter(fn (RawMark $mark): bool => $mark->pay_period_id === $period->id)
                ->map(fn (RawMark $mark): array => $this->details($mark, false))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function details(?RawMark $mark, bool $kept): array
    {
        return [
            'id' => $mark?->id,
            'row_number' => $mark?->row_number,
            'file_name' => $mark?->uploadedFile?->original_name,
            'status' => $mark?->status,
            'employee_external_id' => $mark?->employee_external_id,
            'event_at' => $mark?->event_at?->toDateTimeString(),
            'kept' => $kept,
        ];
    }
}
