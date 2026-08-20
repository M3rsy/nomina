<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\OvertimeDecision;
use App\Services\Payroll\BandSplit;
use Illuminate\Support\Collection;

final class AttendanceDecisionMatcher
{
    /** @param Collection<int, OvertimeDecision> $decisions */
    public function overtime(Collection $decisions, AttendanceSegment $candidate): ?OvertimeDecision
    {
        $decision = $this->record($decisions, 'candidate_key', $candidate);
        if ($decision === null
            || ! in_array($decision->decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)) {
            return null;
        }
        if ((int) ($decision->record_version ?? 1) === 1) {
            return $decision;
        }

        $approved = $this->bandSplit($decision->approved_rate_minutes);
        $rejected = $this->bandSplit($decision->rejected_rate_minutes);

        return in_array($decision->resolution_kind, [
            OvertimeDecision::WHOLE_APPROVE, OvertimeDecision::WHOLE_REJECT, OvertimeDecision::PARTIAL,
        ], true)
            && is_string($decision->resolution_hash) && strlen($decision->resolution_hash) === 64
            && $approved->totalMinutes() === $decision->approved_minutes
            && $rejected->totalMinutes() === $decision->rejected_minutes
            && $decision->approved_minutes + $decision->rejected_minutes === $candidate->minutes
            && $approved->plus($rejected) == $candidate->rateMinutes
            ? $decision
            : null;
    }

    /** @param Collection<int, AttendanceException> $exceptions */
    public function exception(Collection $exceptions, AttendanceSegment $deficit): ?AttendanceException
    {
        $exception = $this->record($exceptions, 'deficit_key', $deficit);

        return $exception !== null
            && in_array($exception->decision, [AttendanceException::GRANTED, AttendanceException::REJECTED, AttendanceException::REVOKED], true)
                ? $exception
                : null;
    }

    private function record(Collection $records, string $key, AttendanceSegment $segment): mixed
    {
        foreach ($segment->identities() as $identity) {
            $record = $records->firstWhere($key, $identity->key);
            if ($record === null) {
                continue;
            }

            return $identity->matchesRecord($record, $key) ? $record : null;
        }

        return null;
    }

    private function bandSplit(?array $rates): BandSplit
    {
        return new BandSplit(
            ordinaryMinutes: (int) ($rates['ordinary'] ?? -1),
            extra25Minutes: (int) ($rates['extra25'] ?? -1),
            extra50Minutes: (int) ($rates['extra50'] ?? -1),
            extra75Minutes: (int) ($rates['extra75'] ?? -1),
            extra100Minutes: (int) ($rates['extra100'] ?? -1),
        );
    }
}
