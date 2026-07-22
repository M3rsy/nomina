<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\JustifiedAbsence;
use App\Models\OvertimeDecision;
use App\Services\Payroll\BandSplit;
use Illuminate\Support\Collection;

class PayrollShiftEvaluator
{
    /**
     * @param  Collection<int, OvertimeDecision>  $currentDecisions
     * @param  Collection<int, AttendanceException>  $currentExceptions
     */
    public function evaluate(
        ShiftOccurrence $occurrence,
        AttendanceShiftAnalysis $analysis,
        Collection $currentDecisions,
        ?JustifiedAbsence $absence = null,
        Collection $currentExceptions = new Collection,
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
            if ($analysis->isHoliday || ! $occurrence->schedule?->is_working_day) {
                return new PayrollShiftEvaluation(
                    status: PayrollShiftEvaluation::SKIP,
                    workDate: $analysis->workDate,
                );
            }

            $hasScheduledInterval = $occurrence->scheduledStart !== null && $occurrence->scheduledEnd !== null;
            $scheduledMinutes = $hasScheduledInterval
                ? $analysis->scheduledMinutes
                : (int) round((float) $occurrence->schedule->base_ordinary_hours * 60);
            try {
                $absenceSnapshot = FullDayAbsenceSnapshot::from($occurrence, $analysis);
            } catch (\InvalidArgumentException) {
                $absenceSnapshot = null;
            }
            $isJustified = $absence !== null
                && $absenceSnapshot !== null
                && $absenceSnapshot->matches($absence);
            $payableRates = $isJustified
                ? ($hasScheduledInterval
                    ? $analysis->scheduledRates
                    : new BandSplit(ordinaryMinutes: $scheduledMinutes))
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
                metadata: $isJustified ? [
                    'justified_absence_id' => $absence->id,
                    'absence_reason' => $absence->reason,
                    'absence_schedule_fingerprint' => $absence->schedule_fingerprint,
                ] : [],
            );
        }

        $decisions = $currentDecisions->keyBy('candidate_key');
        $exceptions = $currentExceptions->keyBy('deficit_key');
        $blockers = collect();
        $payableRates = $analysis->scheduledRates;
        $approvedMinutes = 0;
        $excusedMinutes = 0;
        $exceptionIds = [];

        foreach ($analysis->deficits as $deficit) {
            $exception = $exceptions->get($deficit->key);

            if (! $this->matchesException($exception, $deficit)
                || $exception->decision !== AttendanceException::GRANTED) {
                continue;
            }

            $payableRates = $payableRates->plus($deficit->rateMinutes);
            $excusedMinutes += $deficit->minutes;
            $exceptionIds[] = $exception->id;
        }

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
            scheduledMinutes: $analysis->scheduledMinutes + $analysis->deficits->sum('minutes'),
            recognizedMinutes: $payableRates->totalMinutes(),
            detectedOvertimeMinutes: $analysis->overtimeCandidates->sum('minutes'),
            approvedOvertimeMinutes: $approvedMinutes,
            excusedDeficitMinutes: $excusedMinutes,
            payableRates: $payableRates,
            blockers: $blockers,
            metadata: $excusedMinutes > 0 ? [
                'attendance_exception_ids' => $exceptionIds,
                'excused_deficit_minutes' => $excusedMinutes,
            ] : [],
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

    private function matchesException(?AttendanceException $exception, AttendanceSegment $deficit): bool
    {
        return $exception !== null
            && $exception->fingerprint === $deficit->fingerprint
            && $exception->minutes === $deficit->minutes
            && $exception->starts_at?->equalTo($deficit->start)
            && $exception->ends_at?->equalTo($deficit->end)
            && in_array($exception->decision, [AttendanceException::GRANTED, AttendanceException::REVOKED], true)
            && $exception->rate_minutes === [
                'ordinary' => $deficit->rateMinutes->ordinaryMinutes,
                'extra25' => $deficit->rateMinutes->extra25Minutes,
                'extra50' => $deficit->rateMinutes->extra50Minutes,
                'extra75' => $deficit->rateMinutes->extra75Minutes,
                'extra100' => $deficit->rateMinutes->extra100Minutes,
            ];
    }
}
