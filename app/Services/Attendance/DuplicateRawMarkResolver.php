<?php

namespace App\Services\Attendance;

use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DuplicateRawMarkResolver
{
    public function __construct(
        private RawMarkMutationGuard $mutationGuard,
        private DuplicateRawMarkGroupReader $groups,
    ) {}

    public function resolve(
        PayPeriod $period,
        string $duplicateKey,
        int $keptRawMarkId,
        string $reason,
        int $actorId,
        ?int $uploadedFileId = null,
    ): int {
        $reason = Validator::make(['reason' => trim($reason)], [
            'reason' => ['required', 'string', 'max:500'],
        ])->validate()['reason'];
        $routingMark = RawMark::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereKey($keptRawMarkId)
            ->first();

        if ($routingMark === null) {
            throw ValidationException::withMessages([
                'duplicateResolution' => 'El registro que desea conservar ya no está disponible.',
            ]);
        }

        $expectedKey = $this->groups->identityKey($routingMark);
        if ($expectedKey !== $duplicateKey) {
            throw ValidationException::withMessages([
                'duplicateResolution' => 'El grupo duplicado cambió. Actualice la revisión e inténtelo nuevamente.',
            ]);
        }

        $fileIds = [];
        $resolved = $this->mutationGuard->mutateBatch(
            (int) $period->company_id,
            function () use ($period, $duplicateKey, $keptRawMarkId, $uploadedFileId, &$fileIds): array {
                $marks = $this->activeGroup($period, $duplicateKey, $uploadedFileId);
                $kept = $marks->firstWhere('id', $keptRawMarkId);

                if ($uploadedFileId !== null
                    && ! $marks->contains(fn (RawMark $mark): bool => $mark->uploaded_file_id === $uploadedFileId)) {
                    throw ValidationException::withMessages([
                        'duplicateResolution' => 'El grupo duplicado ya no pertenece al archivo filtrado. Actualice la revisión.',
                    ]);
                }

                if ($marks->count() < 2 || $kept === null) {
                    throw ValidationException::withMessages([
                        'duplicateResolution' => 'El grupo duplicado ya fue resuelto o cambió. Actualice la revisión.',
                    ]);
                }

                $keptIsPreferred = in_array($kept->status, ['valid', 'corrected'], true);
                $hasPreferredCandidate = $marks->contains(fn (RawMark $mark): bool => in_array($mark->status, ['valid', 'corrected'], true));
                if ($hasPreferredCandidate && ! $keptIsPreferred) {
                    throw ValidationException::withMessages([
                        'duplicateResolution' => 'Debe conservar una marca válida o corregida cuando el grupo la contiene.',
                    ]);
                }

                $onlyDuplicateExtras = $keptIsPreferred
                    && $marks->reject(fn (RawMark $mark): bool => $mark->id === $keptRawMarkId)
                        ->every(fn (RawMark $mark): bool => $mark->status === 'duplicate');
                $allMarksAreDuplicates = $marks->every(fn (RawMark $mark): bool => $mark->status === 'duplicate');
                $allMarksAreAssignable = $marks->every(fn (RawMark $mark): bool => $mark->employee_id !== null);
                $shouldPromoteKept = $allMarksAreDuplicates
                    && $allMarksAreAssignable
                    && $kept->status === 'duplicate';
                if (! $onlyDuplicateExtras && ! $shouldPromoteKept) {
                    throw ValidationException::withMessages([
                        'duplicateResolution' => 'El grupo contiene marcas críticas que no son duplicados o no están asignadas a un empleado. Corrija el grupo antes de resolverlo.',
                    ]);
                }

                $fileIds = $marks->pluck('uploaded_file_id')->filter()->unique()->values()->all();

                $targets = $marks
                    ->reject(fn (RawMark $mark): bool => $mark->id === $keptRawMarkId)
                    ->filter(fn (RawMark $mark): bool => $mark->pay_period_id === $period->id)
                    ->modelKeys();

                return $shouldPromoteKept
                    ? collect($targets)->push($keptRawMarkId)->unique()->values()->all()
                    : $targets;
            },
            function (RawMark $mark) use ($actorId, $reason, $duplicateKey, $keptRawMarkId): array {
                $metadata = is_array($mark->metadata) ? $mark->metadata : [];
                $revisions = is_array($metadata['revisions'] ?? null) ? $metadata['revisions'] : [];
                $isKept = $mark->id === $keptRawMarkId;
                $newStatus = $isKept ? 'corrected' : 'deleted';
                $revisions[] = [
                    'action' => $isKept ? 'keep_duplicate_as_corrected' : 'resolve_duplicate',
                    'user_id' => $actorId,
                    'reason' => $reason,
                    'kept_raw_mark_id' => $keptRawMarkId,
                    'duplicate_key' => $duplicateKey,
                    'duplicate_details' => [
                        'employee_external_id' => $mark->employee_external_id,
                        'event_at' => $mark->event_at->toDateTimeString(),
                        'pay_period_id' => $mark->pay_period_id,
                        'uploaded_file_id' => $mark->uploaded_file_id,
                        'row_number' => $mark->row_number,
                    ],
                    'previous_status' => $mark->status,
                    'new_status' => $newStatus,
                    'at' => now()->toDateTimeString(),
                ];
                $metadata['revisions'] = $revisions;

                $mark->update([
                    'status' => $newStatus,
                    'metadata' => $metadata,
                ]);

                return ['id' => $mark->id, 'status' => $newStatus];
            },
        );

        $this->refreshUploadSummaries($fileIds);

        return $resolved->where('status', 'deleted')->count();
    }

    /** @return Collection<int, RawMark> */
    private function activeGroup(PayPeriod $period, string $duplicateKey, ?int $uploadedFileId): Collection
    {
        $separator = strrpos($duplicateKey, '|');
        if ($separator === false) {
            throw ValidationException::withMessages([
                'duplicateResolution' => 'La identidad del grupo duplicado no es válida.',
            ]);
        }

        $externalId = substr($duplicateKey, 0, $separator);
        $eventAt = substr($duplicateKey, $separator + 1);

        return RawMark::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->where('employee_external_id', $externalId)
            ->where('event_at', $eventAt)
            ->where('status', '!=', 'deleted')
            ->where(function ($query): void {
                $query->whereNull('uploaded_file_id')
                    ->orWhereHas('uploadedFile');
            })
            ->orderBy('id')
            ->get();
    }

    /** @param list<int> $fileIds */
    private function refreshUploadSummaries(array $fileIds): void
    {
        foreach ($fileIds as $fileId) {
            $file = UploadedFile::query()->find($fileId);
            if ($file === null) {
                continue;
            }

            $counts = RawMark::withoutCompanyScope()
                ->where('uploaded_file_id', $file->id)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            $file->update([
                'validation_summary' => [
                    'total' => $counts->sum(),
                    'valid' => (int) ($counts['valid'] ?? 0),
                    'duplicate' => (int) ($counts['duplicate'] ?? 0),
                    'out_of_period' => (int) ($counts['out_of_period'] ?? 0),
                    'unknown_employee' => (int) ($counts['unknown_employee'] ?? 0),
                    'invalid_row' => (int) ($counts['invalid'] ?? 0),
                    'deleted' => (int) ($counts['deleted'] ?? 0),
                ],
            ]);
        }
    }
}
