<?php

namespace App\Services\Attendance;

use App\Models\RawMark;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Payroll\BandSplit;
use App\Services\Payroll\BandSplitter;
use App\Services\PayrollRules;
use Carbon\CarbonImmutable;

class AttendanceShiftAnalyzer
{
    private const DURATION_FIRST_ORDINARY_QUOTA_MINUTES = 480;

    private const DURATION_FIRST_OVERTIME_BANDS = [
        ['start' => 0, 'end' => 360, 'bucket' => 'extra75'],
        ['start' => 360, 'end' => 1080, 'bucket' => 'extra25'],
        ['start' => 1080, 'end' => 1440, 'bucket' => 'extra50'],
    ];

    public function __construct(
        private BandSplitter $bandSplitter,
        private PayrollRules $rules,
    ) {}

    public function analyze(
        ShiftOccurrence $occurrence,
        bool $isHoliday = false,
        int $calendarGeneration = 0,
    ): AttendanceShiftAnalysis {
        if ($occurrence->payrollPolicyKey === WorkScheduleProfilePublication::DURATION_FIRST_V2) {
            return $this->analyzeDurationFirst($occurrence, $isHoliday, $calendarGeneration);
        }

        if (($occurrence->payrollPolicyKey !== null || $occurrence->publicationId !== null)
            && $occurrence->payrollPolicyKey !== WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1
            && in_array($occurrence->status, [ShiftOccurrence::RESOLVED, ShiftOccurrence::NO_MARKS], true)) {
            return $this->unsupportedPolicy($occurrence, $isHoliday);
        }

        if ($occurrence->status !== ShiftOccurrence::RESOLVED) {
            $scheduledMinutes = 0;
            $scheduledRates = new BandSplit;
            $deficits = collect();

            if ($occurrence->status === ShiftOccurrence::NO_MARKS
                && $occurrence->scheduledStart !== null
                && $occurrence->scheduledEnd !== null) {
                if (! $this->hasCompleteRateBandCoverage($occurrence, $isHoliday)) {
                    return new AttendanceShiftAnalysis(
                        AttendanceShiftAnalysis::INVALID_RATE_BANDS,
                        $occurrence->workDate,
                        null,
                        null,
                        0,
                        0,
                        new BandSplit,
                        collect(),
                        collect(),
                        $isHoliday,
                        $occurrence->publicationId,
                        $occurrence->payrollPolicyKey,
                    );
                }

                $scheduledMinutes = $this->minutes($occurrence->scheduledStart, $occurrence->scheduledEnd);
                $scheduledRates = $this->ratesFor(
                    $occurrence,
                    $occurrence->scheduledStart,
                    $occurrence->scheduledEnd,
                    false,
                    $isHoliday,
                );

                if (! $isHoliday
                    && $occurrence->schedule?->is_working_day
                    && $scheduledMinutes > 0) {
                    $deficits->push(new AttendanceSegment(
                        'full_day_absence',
                        $occurrence->scheduledStart,
                        $occurrence->scheduledEnd,
                        $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
                        $scheduledRates,
                    ));
                }
            }

            return new AttendanceShiftAnalysis(
                $occurrence->status,
                $occurrence->workDate,
                null,
                null,
                0,
                $scheduledMinutes,
                $scheduledRates,
                $deficits,
                collect(),
                $isHoliday,
                $occurrence->publicationId,
                $occurrence->payrollPolicyKey,
            );
        }

        $entry = CarbonImmutable::parse($occurrence->entryMark()?->event_at);
        $exit = CarbonImmutable::parse($occurrence->exitMark()?->event_at);

        if ($exit->lte($entry)) {
            return new AttendanceShiftAnalysis(
                AttendanceShiftAnalysis::INVALID_INTERVAL,
                $occurrence->workDate,
                $entry,
                $exit,
                0,
                0,
                new BandSplit,
                collect(),
                collect(),
                $isHoliday,
                $occurrence->publicationId,
                $occurrence->payrollPolicyKey,
            );
        }

        // Quantize the observed interval once into complete elapsed minutes anchored at the entry.
        $workedMinutes = $this->minutes($entry, $exit);
        $payableEnd = $entry->addMinutes($workedMinutes);

        if (! $this->hasCompleteRateBandCoverage($occurrence, $isHoliday)) {
            return new AttendanceShiftAnalysis(
                AttendanceShiftAnalysis::INVALID_RATE_BANDS,
                $occurrence->workDate,
                $entry,
                $exit,
                $workedMinutes,
                0,
                new BandSplit,
                collect(),
                collect(),
                $isHoliday,
                $occurrence->publicationId,
                $occurrence->payrollPolicyKey,
            );
        }

        $scheduledStart = $occurrence->scheduledStart;
        $scheduledEnd = $occurrence->scheduledEnd;
        $scheduledMinutes = 0;
        $scheduledRates = new BandSplit;
        $deficits = collect();
        $overtimeCandidates = collect();

        if ($scheduledStart !== null && $scheduledEnd !== null) {
            $preShiftMinutes = 0;
            $postShiftMinutes = 0;

            for ($offset = 0; $offset < $workedMinutes; $offset++) {
                $minuteStart = $entry->addMinutes($offset);

                if ($minuteStart->lt($scheduledStart)) {
                    $preShiftMinutes++;
                } elseif ($minuteStart->lt($scheduledEnd)) {
                    $scheduledMinutes++;
                } else {
                    $postShiftMinutes++;
                }
            }

            $scheduledObservedStart = $entry->addMinutes($preShiftMinutes);
            $scheduledObservedEnd = $scheduledObservedStart->addMinutes($scheduledMinutes);
            $scheduledRates = $this->ratesFor(
                $occurrence,
                $scheduledObservedStart,
                $scheduledObservedEnd,
                false,
                $isHoliday,
            );
            $fingerprint = $this->fingerprint($occurrence, $isHoliday, $calendarGeneration);
            $scheduledDuration = $this->minutes($scheduledStart, $scheduledEnd);
            $missingScheduledMinutes = max(0, $scheduledDuration - $scheduledMinutes);
            $lateMinutes = min(
                $missingScheduledMinutes,
                $scheduledObservedStart->gt($scheduledStart)
                    ? $this->minutes($scheduledStart, $scheduledObservedStart)
                    : 0,
            );
            $earlyMinutes = $missingScheduledMinutes - $lateMinutes;

            if ($lateMinutes > 0) {
                $deficitEnd = $scheduledStart->addMinutes($lateMinutes);
                $deficits->push(new AttendanceSegment(
                    'late_arrival',
                    $scheduledStart,
                    $deficitEnd,
                    $fingerprint,
                    $this->ratesFor($occurrence, $scheduledStart, $deficitEnd, false, $isHoliday),
                ));
            }

            if ($earlyMinutes > 0) {
                $deficitStart = $scheduledEnd->subMinutes($earlyMinutes);
                $deficits->push(new AttendanceSegment(
                    'early_departure',
                    $deficitStart,
                    $scheduledEnd,
                    $fingerprint,
                    $this->ratesFor($occurrence, $deficitStart, $scheduledEnd, false, $isHoliday),
                ));
            }

            if ($preShiftMinutes > 0) {
                $candidateEnd = $entry->addMinutes($preShiftMinutes);
                $overtimeCandidates->push(new AttendanceSegment(
                    'pre_shift',
                    $entry,
                    $candidateEnd,
                    $fingerprint,
                    $this->ratesFor($occurrence, $entry, $candidateEnd, true, $isHoliday),
                ));
            }

            if ($postShiftMinutes > 0) {
                $candidateStart = $payableEnd->subMinutes($postShiftMinutes);
                $overtimeCandidates->push(new AttendanceSegment(
                    'post_shift',
                    $candidateStart,
                    $payableEnd,
                    $fingerprint,
                    $this->ratesFor($occurrence, $candidateStart, $payableEnd, true, $isHoliday),
                ));
            }
        } elseif ($workedMinutes > 0) {
            $overtimeCandidates->push(new AttendanceSegment(
                'non_working',
                $entry,
                $payableEnd,
                $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
                $this->ratesFor($occurrence, $entry, $payableEnd, true, $isHoliday),
            ));
        }

        return new AttendanceShiftAnalysis(
            status: $occurrence->status,
            workDate: $occurrence->workDate,
            entryAt: $entry,
            exitAt: $exit,
            workedMinutes: $workedMinutes,
            scheduledMinutes: $scheduledMinutes,
            scheduledRates: $scheduledRates,
            deficits: $deficits,
            overtimeCandidates: $overtimeCandidates,
            isHoliday: $isHoliday,
            publicationId: $occurrence->publicationId,
            payrollPolicyKey: $occurrence->payrollPolicyKey,
        );
    }

    private function unsupportedPolicy(
        ShiftOccurrence $occurrence,
        bool $isHoliday,
    ): AttendanceShiftAnalysis {
        return new AttendanceShiftAnalysis(
            AttendanceShiftAnalysis::UNSUPPORTED_PAYROLL_POLICY,
            $occurrence->workDate,
            null,
            null,
            0,
            0,
            new BandSplit,
            collect(),
            collect(),
            $isHoliday,
            $occurrence->publicationId,
            $occurrence->payrollPolicyKey,
        );
    }

    private function analyzeDurationFirst(
        ShiftOccurrence $occurrence,
        bool $isHoliday,
        int $calendarGeneration,
    ): AttendanceShiftAnalysis {
        if ($occurrence->status !== ShiftOccurrence::RESOLVED) {
            return new AttendanceShiftAnalysis(
                status: $occurrence->status,
                workDate: $occurrence->workDate,
                entryAt: null,
                exitAt: null,
                workedMinutes: 0,
                scheduledMinutes: 0,
                scheduledRates: new BandSplit,
                deficits: collect(),
                overtimeCandidates: collect(),
                isHoliday: $isHoliday,
                publicationId: $occurrence->publicationId,
                payrollPolicyKey: $occurrence->payrollPolicyKey,
            );
        }

        $entry = CarbonImmutable::parse($occurrence->entryMark()?->event_at);
        $exit = CarbonImmutable::parse($occurrence->exitMark()?->event_at);

        if ($exit->lte($entry)) {
            return new AttendanceShiftAnalysis(
                status: AttendanceShiftAnalysis::INVALID_INTERVAL,
                workDate: $occurrence->workDate,
                entryAt: $entry,
                exitAt: $exit,
                workedMinutes: 0,
                scheduledMinutes: 0,
                scheduledRates: new BandSplit,
                deficits: collect(),
                overtimeCandidates: collect(),
                isHoliday: $isHoliday,
                publicationId: $occurrence->publicationId,
                payrollPolicyKey: $occurrence->payrollPolicyKey,
            );
        }

        $workedMinutes = $this->minutes($entry, $exit);

        if ($isHoliday || $occurrence->workDate->dayOfWeek === PayrollRules::DAY_SUNDAY) {
            return $this->durationFirstOverride($occurrence, $entry, $exit, $workedMinutes, $isHoliday);
        }

        $ordinaryMinutes = min($workedMinutes, self::DURATION_FIRST_ORDINARY_QUOTA_MINUTES);
        $payableEnd = $entry->addMinutes($workedMinutes);
        $postQuotaMinutes = max(0, $workedMinutes - self::DURATION_FIRST_ORDINARY_QUOTA_MINUTES);
        $residualMinutes = $postQuotaMinutes % 60;
        $excludedTransferMinutes = $postQuotaMinutes >= 60
            && $residualMinutes >= 1
            && $residualMinutes <= 30 ? $residualMinutes : 0;
        $recognizedEnd = $payableEnd->subMinutes($excludedTransferMinutes);
        $overtimeCandidates = collect();
        $variations = collect();

        if ($workedMinutes >= self::DURATION_FIRST_ORDINARY_QUOTA_MINUTES
            && $occurrence->scheduledStart !== null
            && $entry->gt($occurrence->scheduledStart->addMinutes(20))) {
            $variations->push(new AttendanceVariation(
                'schedule_entry',
                $entry,
                $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
            ));
        }

        if ($workedMinutes > self::DURATION_FIRST_ORDINARY_QUOTA_MINUTES) {
            $candidateStart = $entry->addMinutes(self::DURATION_FIRST_ORDINARY_QUOTA_MINUTES);
            $overtimeCandidates->push($this->durationFirstOvertimeCandidate(
                $occurrence,
                $candidateStart,
                $recognizedEnd,
                $isHoliday,
                $calendarGeneration,
            ));
        }

        return new AttendanceShiftAnalysis(
            status: $occurrence->status,
            workDate: $occurrence->workDate,
            entryAt: $entry,
            exitAt: $exit,
            workedMinutes: $workedMinutes,
            scheduledMinutes: $ordinaryMinutes,
            scheduledRates: new BandSplit(ordinaryMinutes: $ordinaryMinutes),
            deficits: collect(),
            overtimeCandidates: $overtimeCandidates,
            isHoliday: $isHoliday,
            publicationId: $occurrence->publicationId,
            payrollPolicyKey: $occurrence->payrollPolicyKey,
            variations: $variations,
            excludedTransferMinutes: $excludedTransferMinutes,
        );
    }

    private function durationFirstOverride(
        ShiftOccurrence $occurrence,
        CarbonImmutable $entry,
        CarbonImmutable $exit,
        int $workedMinutes,
        bool $isHoliday,
    ): AttendanceShiftAnalysis {
        return new AttendanceShiftAnalysis(
            status: $occurrence->status,
            workDate: $occurrence->workDate,
            entryAt: $entry,
            exitAt: $exit,
            workedMinutes: $workedMinutes,
            scheduledMinutes: $workedMinutes,
            scheduledRates: new BandSplit(extra100Minutes: $workedMinutes),
            deficits: collect(),
            overtimeCandidates: collect(),
            isHoliday: $isHoliday,
            publicationId: $occurrence->publicationId,
            payrollPolicyKey: $occurrence->payrollPolicyKey,
        );
    }

    private function durationFirstOvertimeCandidate(
        ShiftOccurrence $occurrence,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $isHoliday,
        int $calendarGeneration,
    ): AttendanceSegment {
        return new AttendanceSegment(
            'post_quota_overtime',
            $start,
            $end,
            $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
            $this->bandSplitter->split($start, $end, self::DURATION_FIRST_OVERTIME_BANDS),
        );
    }

    private function minutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $end->gt($start) ? (int) floor($start->diffInSeconds($end) / 60) : 0;
    }

    private function fingerprint(ShiftOccurrence $occurrence, bool $isHoliday, int $calendarGeneration): string
    {
        $parts = [
            $occurrence->assignment?->id,
            $occurrence->schedule?->id,
        ];

        if ($occurrence->publicationId !== null || $occurrence->payrollPolicyKey !== null) {
            $parts[] = $occurrence->publicationId;
            $parts[] = $occurrence->payrollPolicyKey;
        }

        array_push(
            $parts,
            $occurrence->schedule?->start_time,
            $occurrence->schedule?->end_time,
            json_encode($occurrence->schedule?->banding_json),
            $occurrence->workDate->toDateString(),
            $isHoliday ? 'holiday' : 'regular',
            $occurrence->factGeneration,
            $occurrence->entryMark()?->id,
            $occurrence->entryMark()?->event_at?->toIso8601String(),
            $this->markRevisionGeneration($occurrence->entryMark()),
            $occurrence->exitMark()?->id,
            $occurrence->exitMark()?->event_at?->toIso8601String(),
            $this->markRevisionGeneration($occurrence->exitMark()),
        );

        if ($calendarGeneration > 0) {
            $parts[] = $calendarGeneration;
        }

        return hash('sha256', implode('|', $parts));
    }

    private function markRevisionGeneration(?RawMark $mark): string
    {
        $revisions = $mark?->metadata['revisions'] ?? [];

        return hash('sha256', json_encode($revisions, JSON_THROW_ON_ERROR));
    }

    private function hasCompleteRateBandCoverage(ShiftOccurrence $occurrence, bool $isHoliday): bool
    {
        return $isHoliday
            || $occurrence->workDate->dayOfWeek === PayrollRules::DAY_SUNDAY
            || $this->rules->hasCompleteRateBandCoverage($occurrence->schedule?->banding_json);
    }

    private function ratesFor(
        ShiftOccurrence $occurrence,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $isCandidate,
        bool $isHoliday,
    ): BandSplit {
        if ($isHoliday || $occurrence->workDate->dayOfWeek === PayrollRules::DAY_SUNDAY) {
            return new BandSplit(extra100Minutes: $this->minutes($start, $end));
        }

        $rates = $this->bandSplitter->split(
            $start,
            $end,
            $this->rules->normalizedOvertimeBands($occurrence->schedule?->banding_json),
        );

        if (! $isCandidate || $occurrence->workDate->dayOfWeek !== PayrollRules::DAY_SATURDAY) {
            return $rates;
        }

        return new BandSplit(
            extra25Minutes: $rates->extra25Minutes + $rates->ordinaryMinutes,
            extra50Minutes: $rates->extra50Minutes,
            extra75Minutes: $rates->extra75Minutes,
            extra100Minutes: $rates->extra100Minutes,
        );
    }
}
