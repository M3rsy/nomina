<?php

namespace App\Services\Attendance;

use App\Models\RawMark;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Payroll\BandSplit;
use App\Services\Payroll\Policy\DurationFirstPolicyDefinition;
use App\Services\Payroll\Policy\PayrollPolicyCatalog;
use App\Services\Payroll\Policy\PayrollPolicyDefinition;
use App\Services\Payroll\Policy\ScheduleOverlapPolicyDefinition;
use App\Services\Payroll\Policy\UnsupportedPayrollPolicy;
use Carbon\CarbonImmutable;

class AttendanceShiftAnalyzer
{
    public function __construct(private PayrollPolicyCatalog $policyCatalog) {}

    public function analyze(
        ShiftOccurrence $occurrence,
        bool $isHoliday = false,
        int $calendarGeneration = 0,
    ): AttendanceShiftAnalysis {
        if ($occurrence->payrollPolicyKey === null
            && $occurrence->publicationId !== null
            && in_array($occurrence->status, [ShiftOccurrence::RESOLVED, ShiftOccurrence::NO_MARKS], true)) {
            return $this->unsupportedPolicy($occurrence, $isHoliday);
        }

        try {
            $policy = $this->policyCatalog->resolve(
                $occurrence->payrollPolicyKey ?? WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1,
            );
        } catch (UnsupportedPayrollPolicy) {
            if (in_array($occurrence->status, [ShiftOccurrence::RESOLVED, ShiftOccurrence::NO_MARKS], true)) {
                return $this->unsupportedPolicy($occurrence, $isHoliday);
            }

            $policy = $this->policyCatalog->resolve(WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1);
        }

        if ($policy instanceof DurationFirstPolicyDefinition) {
            return $this->analyzeDurationFirst($occurrence, $policy, $isHoliday, $calendarGeneration);
        }

        if (! $policy instanceof ScheduleOverlapPolicyDefinition) {
            return $this->unsupportedPolicy($occurrence, $isHoliday);
        }

        if ($occurrence->status !== ShiftOccurrence::RESOLVED) {
            $scheduledMinutes = 0;
            $scheduledRates = new BandSplit;
            $deficits = collect();

            if ($occurrence->status === ShiftOccurrence::NO_MARKS
                && $occurrence->scheduledStart !== null
                && $occurrence->scheduledEnd !== null) {
                $scheduledMinutes = $this->minutes(
                    $policy,
                    $occurrence->scheduledStart,
                    $occurrence->scheduledEnd,
                );
                $scheduledRates = $this->ratesFor(
                    $policy,
                    $occurrence,
                    $occurrence->scheduledStart,
                    $occurrence->scheduledEnd,
                    false,
                    $isHoliday,
                );

                if ($policy->shouldCreateFullDayAbsence(
                    $isHoliday,
                    (bool) $occurrence->schedule?->is_working_day,
                    $scheduledMinutes,
                )) {
                    $deficits->push($this->withLegacyIdentity(new AttendanceSegment(
                        'full_day_absence',
                        $occurrence->scheduledStart,
                        $occurrence->scheduledEnd,
                        $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
                        $scheduledRates,
                    ), $this->legacyFingerprint($occurrence, $isHoliday, $calendarGeneration)));
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
        $workedMinutes = $this->minutes($policy, $entry, $exit);
        $payableEnd = $entry->addMinutes($workedMinutes);

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
                $policy,
                $occurrence,
                $scheduledObservedStart,
                $scheduledObservedEnd,
                false,
                $isHoliday,
            );
            $fingerprint = $this->fingerprint($occurrence, $isHoliday, $calendarGeneration);
            $legacyFingerprint = $this->legacyFingerprint($occurrence, $isHoliday, $calendarGeneration);
            $scheduledDuration = $this->minutes($policy, $scheduledStart, $scheduledEnd);
            $missingScheduledMinutes = max(0, $scheduledDuration - $scheduledMinutes);
            $lateMinutes = min(
                $missingScheduledMinutes,
                $scheduledObservedStart->gt($scheduledStart)
                    ? $this->minutes($policy, $scheduledStart, $scheduledObservedStart)
                    : 0,
            );
            $earlyMinutes = $missingScheduledMinutes - $lateMinutes;

            if ($policy->shouldCreateScheduledDeficit(
                $lateMinutes,
                $isHoliday,
                $occurrence->workDate->dayOfWeek,
            )) {
                $deficitEnd = $scheduledStart->addMinutes($lateMinutes);
                $deficits->push($this->withLegacyIdentity(new AttendanceSegment(
                    'late_arrival',
                    $scheduledStart,
                    $deficitEnd,
                    $fingerprint,
                    $this->ratesFor($policy, $occurrence, $scheduledStart, $deficitEnd, false, $isHoliday),
                ), $legacyFingerprint));
            }

            if ($policy->shouldCreateScheduledDeficit(
                $earlyMinutes,
                $isHoliday,
                $occurrence->workDate->dayOfWeek,
            )) {
                $deficitStart = $scheduledEnd->subMinutes($earlyMinutes);
                $deficits->push($this->withLegacyIdentity(new AttendanceSegment(
                    'early_departure',
                    $deficitStart,
                    $scheduledEnd,
                    $fingerprint,
                    $this->ratesFor($policy, $occurrence, $deficitStart, $scheduledEnd, false, $isHoliday),
                ), $legacyFingerprint));
            }

            if ($policy->shouldCreateOvertimeCandidate(
                $preShiftMinutes,
                $isHoliday,
                $occurrence->workDate->dayOfWeek,
            )) {
                $candidateEnd = $entry->addMinutes($preShiftMinutes);
                $overtimeCandidates->push($this->withLegacyIdentity(new AttendanceSegment(
                    'pre_shift',
                    $entry,
                    $candidateEnd,
                    $fingerprint,
                    $this->ratesFor($policy, $occurrence, $entry, $candidateEnd, true, $isHoliday),
                ), $legacyFingerprint));
            }

            if ($policy->shouldCreateOvertimeCandidate(
                $postShiftMinutes,
                $isHoliday,
                $occurrence->workDate->dayOfWeek,
            )) {
                $candidateStart = $payableEnd->subMinutes($postShiftMinutes);
                $overtimeCandidates->push($this->withLegacyIdentity(new AttendanceSegment(
                    'post_shift',
                    $candidateStart,
                    $payableEnd,
                    $fingerprint,
                    $this->ratesFor($policy, $occurrence, $candidateStart, $payableEnd, true, $isHoliday),
                ), $legacyFingerprint));
            }
        } elseif ($policy->shouldCreateOvertimeCandidate(
            $workedMinutes,
            $isHoliday,
            $occurrence->workDate->dayOfWeek,
        )) {
            $overtimeCandidates->push($this->withLegacyIdentity(new AttendanceSegment(
                'non_working',
                $entry,
                $payableEnd,
                $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
                $this->ratesFor($policy, $occurrence, $entry, $payableEnd, true, $isHoliday),
            ), $this->legacyFingerprint($occurrence, $isHoliday, $calendarGeneration)));
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
        DurationFirstPolicyDefinition $policy,
        bool $isHoliday,
        int $calendarGeneration,
    ): AttendanceShiftAnalysis {
        if ($occurrence->status !== ShiftOccurrence::RESOLVED) {
            $deficits = collect();
            $shortfall = $occurrence->status === ShiftOccurrence::NO_MARKS
                ? $this->durationFirstShortfall($occurrence, $policy, 0, $isHoliday, $calendarGeneration)
                : null;

            if ($shortfall !== null) {
                $deficits->push($shortfall);
            }

            return new AttendanceShiftAnalysis(
                status: $occurrence->status,
                workDate: $occurrence->workDate,
                entryAt: null,
                exitAt: null,
                workedMinutes: 0,
                scheduledMinutes: 0,
                scheduledRates: new BandSplit,
                deficits: $deficits,
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

        $workedMinutes = $policy->completeElapsedMinutes((int) $entry->diffInSeconds($exit));
        $dayOfWeek = $occurrence->workDate->dayOfWeek;

        if ($policy->overrideRateBucket($isHoliday, $dayOfWeek) !== null) {
            return $this->durationFirstOverride($occurrence, $policy, $entry, $exit, $workedMinutes, $isHoliday);
        }

        $ordinaryMinutes = $policy->ordinaryMinutes($workedMinutes, $isHoliday, $dayOfWeek);
        $payableEnd = $entry->addMinutes($workedMinutes);
        $excludedTransferMinutes = $policy->excludedTransferMinutes($workedMinutes, $isHoliday, $dayOfWeek);
        $recognizedEnd = $payableEnd->subMinutes($excludedTransferMinutes);
        $deficits = collect();
        $overtimeCandidates = collect();
        $variations = collect();

        if ($shortfall = $this->durationFirstShortfall(
            $occurrence,
            $policy,
            $workedMinutes,
            $isHoliday,
            $calendarGeneration,
        )) {
            $deficits->push($shortfall);
        }

        $minutesAfterScheduledStart = $occurrence->scheduledStart === null
            ? null
            : ($entry->gt($occurrence->scheduledStart)
                ? (int) ceil($occurrence->scheduledStart->diffInSeconds($entry) / 60)
                : 0);

        if ($policy->shouldRecordEntryVariation(
            $workedMinutes,
            $minutesAfterScheduledStart,
            $isHoliday,
            $dayOfWeek,
        )) {
            $variations->push(new AttendanceVariation(
                'schedule_entry',
                $entry,
                $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
            ));
        }

        if ($policy->shouldCreateOvertimeCandidate($workedMinutes, $isHoliday, $dayOfWeek)) {
            $candidateStart = $entry->addMinutes($ordinaryMinutes);
            $overtimeCandidates->push($this->durationFirstOvertimeCandidate(
                $occurrence,
                $policy,
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
            deficits: $deficits,
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
        DurationFirstPolicyDefinition $policy,
        CarbonImmutable $entry,
        CarbonImmutable $exit,
        int $workedMinutes,
        bool $isHoliday,
    ): AttendanceShiftAnalysis {
        $overrideMinutes = $policy->extra100Minutes(
            $workedMinutes,
            $isHoliday,
            $occurrence->workDate->dayOfWeek,
        );

        return new AttendanceShiftAnalysis(
            status: $occurrence->status,
            workDate: $occurrence->workDate,
            entryAt: $entry,
            exitAt: $exit,
            workedMinutes: $workedMinutes,
            scheduledMinutes: $overrideMinutes,
            scheduledRates: new BandSplit(extra100Minutes: $overrideMinutes),
            deficits: collect(),
            overtimeCandidates: collect(),
            isHoliday: $isHoliday,
            publicationId: $occurrence->publicationId,
            payrollPolicyKey: $occurrence->payrollPolicyKey,
        );
    }

    private function durationFirstOvertimeCandidate(
        ShiftOccurrence $occurrence,
        DurationFirstPolicyDefinition $policy,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $isHoliday,
        int $calendarGeneration,
    ): AttendanceSegment {
        return $this->withLegacyIdentity(new AttendanceSegment(
            'post_quota_overtime',
            $start,
            $end,
            $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
            $this->splitByPolicy($policy, $start, $end, $policy->overtimeBucketAt(...)),
        ), $this->legacyFingerprint($occurrence, $isHoliday, $calendarGeneration));
    }

    private function durationFirstShortfall(
        ShiftOccurrence $occurrence,
        DurationFirstPolicyDefinition $policy,
        int $workedMinutes,
        bool $isHoliday,
        int $calendarGeneration,
    ): ?AttendanceSegment {
        $minutes = $policy->shortfallMinutes(
            $workedMinutes,
            $isHoliday,
            $occurrence->workDate->dayOfWeek,
            (bool) $occurrence->schedule?->is_working_day,
        );

        if ($minutes < 1) {
            return null;
        }

        return $this->withLegacyIdentity(new AttendanceSegment(
            kind: 'daily_shortfall',
            start: null,
            end: null,
            fingerprint: $this->fingerprint($occurrence, $isHoliday, $calendarGeneration),
            rateMinutes: new BandSplit(ordinaryMinutes: $minutes),
            minutes: $minutes,
        ), $this->legacyFingerprint($occurrence, $isHoliday, $calendarGeneration));
    }

    private function splitByPolicy(
        PayrollPolicyDefinition $policy,
        CarbonImmutable $start,
        CarbonImmutable $end,
        callable $bucketAt,
    ): BandSplit {
        $totals = [
            'ordinary' => 0,
            'extra25' => 0,
            'extra50' => 0,
            'extra75' => 0,
            'extra100' => 0,
        ];
        $wholeMinutes = $policy->completeElapsedMinutes((int) $start->diffInSeconds($end));

        for ($offset = 0; $offset < $wholeMinutes; $offset++) {
            $instant = $start->addMinutes($offset);
            $totals[$bucketAt($instant->hour * 60 + $instant->minute)]++;
        }

        return new BandSplit(
            ordinaryMinutes: $totals['ordinary'],
            extra25Minutes: $totals['extra25'],
            extra50Minutes: $totals['extra50'],
            extra75Minutes: $totals['extra75'],
            extra100Minutes: $totals['extra100'],
        );
    }

    private function minutes(
        PayrollPolicyDefinition $policy,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): int {
        return $end->gt($start)
            ? $policy->completeElapsedMinutes((int) $start->diffInSeconds($end))
            : 0;
    }

    private function fingerprint(ShiftOccurrence $occurrence, bool $isHoliday, int $calendarGeneration): string
    {
        return $this->fingerprintFor($occurrence, $isHoliday, $calendarGeneration, true);
    }

    private function legacyFingerprint(ShiftOccurrence $occurrence, bool $isHoliday, int $calendarGeneration): ?string
    {
        if ($occurrence->payrollPolicyKey !== WorkScheduleProfilePublication::SCHEDULE_OVERLAP_V1) {
            return null;
        }

        return $this->fingerprintFor($occurrence, $isHoliday, $calendarGeneration, false);
    }

    private function withLegacyIdentity(AttendanceSegment $segment, ?string $legacyFingerprint): AttendanceSegment
    {
        return $legacyFingerprint === null
            ? $segment
            : $segment->withCompatibleFingerprint($legacyFingerprint);
    }

    private function fingerprintFor(
        ShiftOccurrence $occurrence,
        bool $isHoliday,
        int $calendarGeneration,
        bool $includePublicationIdentity,
    ): string {
        $parts = [
            $occurrence->assignment?->id,
            $occurrence->schedule?->id,
        ];

        if ($includePublicationIdentity && ($occurrence->publicationId !== null || $occurrence->payrollPolicyKey !== null)) {
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

    private function ratesFor(
        ScheduleOverlapPolicyDefinition $policy,
        ShiftOccurrence $occurrence,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $isCandidate,
        bool $isHoliday,
    ): BandSplit {
        return $this->splitByPolicy(
            $policy,
            $start,
            $end,
            fn (int $minuteOfDay): string => $policy->rateBucketAt(
                $minuteOfDay,
                $isHoliday,
                $occurrence->workDate->dayOfWeek,
                $isCandidate,
            ),
        );
    }
}
