<?php

namespace App\Services\Attendance;

use App\Models\JustifiedAbsence;
use App\Models\OvertimeDecision;
use App\Services\Payroll\BandSplit;
use Illuminate\Support\Collection;

class PayrollShiftEvaluator
{
    /**
     * @param  Collection<int, OvertimeDecision>  $currentDecisions
     */
    public function evaluate(
        ShiftOccurrence $occurrence,
        AttendanceShiftAnalysis $analysis,
        Collection $currentDecisions,
        ?JustifiedAbsence $absence = null,
    ): PayrollShiftEvaluation {
        if (! in_array($analysis->status, [ShiftOccurrence::RESOLVED, ShiftOccurrence::NO_MARKS], true)) {
            return new PayrollShiftEvaluation(
                status: PayrollShiftEvaluation::BLOCKED,
                workDate: $analysis->workDate,
                entryAt: $analysis->entryAt,
                exitAt: $analysis->exitAt,
                blockers: collect([['code' => $analysis->status]]),
            );
        }

        if ($analysis->status === ShiftOccurrence::NO_MARKS) {
            if (! $occurrence->schedule?->is_working_day) {
                return new PayrollShiftEvaluation(
                    status: PayrollShiftEvaluation::SKIP,
                    workDate: $analysis->workDate,
                );
            }

            $scheduledMinutes = $occurrence->scheduledStart !== null && $occurrence->scheduledEnd !== null
                ? (int) floor($occurrence->scheduledStart->diffInSeconds($occurrence->scheduledEnd) / 60)
                : (int) round((float) $occurrence->schedule->base_ordinary_hours * 60);
            $isJustified = $absence !== null;
            $payableRates = $isJustified
                ? new BandSplit(ordinaryMinutes: $scheduledMinutes)
                : new BandSplit;

            return new PayrollShiftEvaluation(
                status: PayrollShiftEvaluation::PROCESSABLE,
                workDate: $analysis->workDate,
                scheduledMinutes: $scheduledMinutes,
                recognizedMinutes: $payableRates->totalMinutes(),
                payableRates: $payableRates,
                isAbsence: true,
                isJustified: $isJustified,
                unjustified: ! $isJustified,
                metadata: $isJustified ? ['absence_reason' => $absence->reason] : [],
            );
        }

        $decisions = $currentDecisions->keyBy('candidate_key');
        $blockers = collect();
        $payableRates = $analysis->scheduledRates;
        $approvedMinutes = 0;

        foreach ($analysis->overtimeCandidates as $candidate) {
            $decision = $decisions->get($candidate->key);

            if (! $this->matches($decision, $candidate)) {
                $blockers->push([
                    'code' => 'pending_overtime_candidate',
                    'candidate_key' => $candidate->key,
                ]);

                continue;
            }

            if ($decision->decision === OvertimeDecision::APPROVED) {
                $payableRates = $payableRates->plus($candidate->rateMinutes);
                $approvedMinutes += $candidate->minutes;
            }
        }

        return new PayrollShiftEvaluation(
            status: $blockers->isEmpty()
                ? PayrollShiftEvaluation::PROCESSABLE
                : PayrollShiftEvaluation::BLOCKED,
            workDate: $analysis->workDate,
            entryAt: $analysis->entryAt,
            exitAt: $analysis->exitAt,
            workedMinutes: $analysis->workedMinutes,
            scheduledMinutes: $analysis->scheduledMinutes,
            recognizedMinutes: $payableRates->totalMinutes(),
            detectedOvertimeMinutes: $analysis->overtimeCandidates->sum('minutes'),
            approvedOvertimeMinutes: $approvedMinutes,
            payableRates: $payableRates,
            blockers: $blockers,
        );
    }

    private function matches(?OvertimeDecision $decision, AttendanceSegment $candidate): bool
    {
        return $decision !== null
            && $decision->fingerprint === $candidate->fingerprint
            && $decision->minutes === $candidate->minutes
            && $decision->starts_at?->equalTo($candidate->start)
            && $decision->ends_at?->equalTo($candidate->end)
            && in_array($decision->decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)
            && $decision->rate_minutes === [
                'ordinary' => $candidate->rateMinutes->ordinaryMinutes,
                'extra25' => $candidate->rateMinutes->extra25Minutes,
                'extra50' => $candidate->rateMinutes->extra50Minutes,
                'extra75' => $candidate->rateMinutes->extra75Minutes,
                'extra100' => $candidate->rateMinutes->extra100Minutes,
            ];
    }
}
