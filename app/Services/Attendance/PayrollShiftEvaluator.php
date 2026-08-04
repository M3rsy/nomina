<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\OvertimeDecision;
use App\Services\Payroll\BandSplit;
use Carbon\CarbonInterface;
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
        Collection $currentExceptions = new Collection,
    ): PayrollShiftEvaluation {
        $provenance = $this->provenance($occurrence);

        if (! in_array($analysis->status, [ShiftOccurrence::RESOLVED, ShiftOccurrence::NO_MARKS], true)) {
            return new PayrollShiftEvaluation(
                status: PayrollShiftEvaluation::BLOCKED,
                workDate: $analysis->workDate,
                entryAt: $analysis->entryAt,
                exitAt: $analysis->exitAt,
                blockers: collect([['code' => $analysis->status]]),
                metadata: $provenance,
                publicationId: $occurrence->publicationId,
                payrollPolicyKey: $occurrence->payrollPolicyKey,
            );
        }

        if ($analysis->status === ShiftOccurrence::NO_MARKS) {
            if ($analysis->isHoliday || ! $occurrence->schedule?->is_working_day) {
                return new PayrollShiftEvaluation(
                    status: PayrollShiftEvaluation::SKIP,
                    workDate: $analysis->workDate,
                    metadata: $provenance,
                    publicationId: $occurrence->publicationId,
                    payrollPolicyKey: $occurrence->payrollPolicyKey,
                );
            }

            $hasScheduledInterval = $occurrence->scheduledStart !== null && $occurrence->scheduledEnd !== null;
            $scheduledMinutes = $hasScheduledInterval
                ? $analysis->scheduledMinutes
                : (int) round((float) $occurrence->schedule->base_ordinary_hours * 60);
            $deficit = $analysis->deficits->firstWhere('kind', 'full_day_absence');
            $deficit ??= $analysis->deficits->firstWhere('kind', 'daily_shortfall');
            $exception = $deficit === null
                ? null
                : $currentExceptions->keyBy('deficit_key')->get($deficit->key);
            $isJustified = $deficit !== null
                && $this->matchesException($exception, $deficit)
                && $exception->decision === AttendanceException::GRANTED;
            $payableRates = $isJustified
                ? $deficit->rateMinutes
                : new BandSplit;
            $pendingDailyShortfall = $deficit?->kind === 'daily_shortfall'
                && (! $this->matchesException($exception, $deficit)
                    || $exception->decision === AttendanceException::REVOKED);

            return new PayrollShiftEvaluation(
                status: $pendingDailyShortfall
                    ? PayrollShiftEvaluation::BLOCKED
                    : PayrollShiftEvaluation::PROCESSABLE,
                workDate: $analysis->workDate,
                scheduledMinutes: $scheduledMinutes,
                recognizedMinutes: $payableRates->totalMinutes(),
                payableRates: $payableRates,
                isAbsence: true,
                isJustified: $isJustified,
                unjustified: ! $isJustified,
                excusedDeficitMinutes: $isJustified ? $deficit->minutes : 0,
                blockers: $pendingDailyShortfall
                    ? collect([['code' => 'pending_daily_shortfall', 'deficit_key' => $deficit->key]])
                    : collect(),
                metadata: [...$provenance, ...($isJustified ? [
                    'attendance_exception_ids' => [$exception->id],
                    'excused_deficit_minutes' => $deficit->minutes,
                ] : [])],
                publicationId: $occurrence->publicationId,
                payrollPolicyKey: $occurrence->payrollPolicyKey,
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

            if ($deficit->kind === 'daily_shortfall') {
                if (! $this->matchesException($exception, $deficit)
                    || $exception->decision === AttendanceException::REVOKED) {
                    $blockers->push([
                        'code' => 'pending_daily_shortfall',
                        'deficit_key' => $deficit->key,
                    ]);

                    continue;
                }

                if ($exception->decision === AttendanceException::REJECTED) {
                    continue;
                }
            }

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
                $approvedRates = (int) ($decision->record_version ?? 1) === 2
                    ? $this->bandSplit($decision->approved_rate_minutes)
                    : $candidate->rateMinutes;
                $payableRates = $payableRates->plus($approvedRates);
                $approvedMinutes += (int) ($decision->approved_minutes ?? $candidate->minutes);
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
            metadata: [...$provenance, ...($excusedMinutes > 0 ? [
                'attendance_exception_ids' => $exceptionIds,
                'excused_deficit_minutes' => $excusedMinutes,
            ] : [])],
            publicationId: $occurrence->publicationId,
            payrollPolicyKey: $occurrence->payrollPolicyKey,
        );
    }

    /** @return array<string, int|string> */
    private function provenance(ShiftOccurrence $occurrence): array
    {
        if ($occurrence->publicationId === null || $occurrence->payrollPolicyKey === null) {
            return [];
        }

        return [
            'work_schedule_profile_publication_id' => $occurrence->publicationId,
            'payroll_policy_key' => $occurrence->payrollPolicyKey,
        ];
    }

    private function matches(?OvertimeDecision $decision, AttendanceSegment $candidate): bool
    {
        $candidateRates = [
            'ordinary' => $candidate->rateMinutes->ordinaryMinutes,
            'extra25' => $candidate->rateMinutes->extra25Minutes,
            'extra50' => $candidate->rateMinutes->extra50Minutes,
            'extra75' => $candidate->rateMinutes->extra75Minutes,
            'extra100' => $candidate->rateMinutes->extra100Minutes,
        ];
        $matchesCandidate = $decision !== null
            && $decision->fingerprint === $candidate->fingerprint
            && $decision->minutes === $candidate->minutes
            && $decision->starts_at?->equalTo($candidate->start)
            && $decision->ends_at?->equalTo($candidate->end)
            && in_array($decision->decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)
            && $decision->rate_minutes === $candidateRates;
        if (! $matchesCandidate || (int) ($decision->record_version ?? 1) === 1) {
            return $matchesCandidate;
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
            && $approved->plus($rejected) == $candidate->rateMinutes;
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

    private function matchesException(?AttendanceException $exception, AttendanceSegment $deficit): bool
    {
        return $exception !== null
            && $exception->fingerprint === $deficit->fingerprint
            && $exception->minutes === $deficit->minutes
            && $this->sameBoundary($exception->starts_at, $deficit->start)
            && $this->sameBoundary($exception->ends_at, $deficit->end)
            && in_array($exception->decision, [AttendanceException::GRANTED, AttendanceException::REJECTED, AttendanceException::REVOKED], true)
            && $exception->rate_minutes === [
                'ordinary' => $deficit->rateMinutes->ordinaryMinutes,
                'extra25' => $deficit->rateMinutes->extra25Minutes,
                'extra50' => $deficit->rateMinutes->extra50Minutes,
                'extra75' => $deficit->rateMinutes->extra75Minutes,
                'extra100' => $deficit->rateMinutes->extra100Minutes,
            ];
    }

    private function sameBoundary(?CarbonInterface $stored, ?CarbonInterface $current): bool
    {
        return ($stored === null && $current === null)
            || ($stored !== null && $current !== null && $stored->equalTo($current));
    }
}
