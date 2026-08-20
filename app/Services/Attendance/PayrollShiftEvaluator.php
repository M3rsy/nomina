<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\OvertimeDecision;
use App\Services\Payroll\BandSplit;
use Illuminate\Support\Collection;

class PayrollShiftEvaluator
{
    public function __construct(private AttendanceDecisionMatcher $decisionMatcher) {}

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
                : $this->decisionMatcher->exception($currentExceptions, $deficit);
            $isJustified = $deficit !== null
                && $exception !== null
                && $exception->decision === AttendanceException::GRANTED;
            $payableRates = $isJustified
                ? $deficit->rateMinutes
                : new BandSplit;
            $pendingDailyShortfall = $deficit?->kind === 'daily_shortfall'
                && ($exception === null
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

        $blockers = collect();
        $payableRates = $analysis->scheduledRates;
        $approvedMinutes = 0;
        $excusedMinutes = 0;
        $exceptionIds = [];

        foreach ($analysis->deficits as $deficit) {
            $exception = $this->decisionMatcher->exception($currentExceptions, $deficit);

            if ($deficit->kind === 'daily_shortfall') {
                if ($exception === null
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

            if ($exception?->decision !== AttendanceException::GRANTED) {
                continue;
            }

            $payableRates = $payableRates->plus($deficit->rateMinutes);
            $excusedMinutes += $deficit->minutes;
            $exceptionIds[] = $exception->id;
        }

        foreach ($analysis->overtimeCandidates as $candidate) {
            $decision = $this->decisionMatcher->overtime($currentDecisions, $candidate);

            if ($decision === null) {
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
