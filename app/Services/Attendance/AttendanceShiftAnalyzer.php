<?php

namespace App\Services\Attendance;

use App\Models\RawMark;
use App\Services\Payroll\BandSplit;
use App\Services\Payroll\BandSplitter;
use App\Services\PayrollRules;
use Carbon\CarbonImmutable;

class AttendanceShiftAnalyzer
{
    public function __construct(
        private BandSplitter $bandSplitter,
        private PayrollRules $rules,
    ) {}

    public function analyze(ShiftOccurrence $occurrence, bool $isHoliday = false): AttendanceShiftAnalysis
    {
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
                        $this->fingerprint($occurrence, $isHoliday),
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
            );
        }

        // Payroll is minute-based: normalize the pair once so later partitions cannot discard seconds repeatedly.
        $minuteEntry = $entry->startOfMinute();
        $minuteExit = $exit->startOfMinute();

        if (! $this->hasCompleteRateBandCoverage($occurrence, $isHoliday)) {
            return new AttendanceShiftAnalysis(
                AttendanceShiftAnalysis::INVALID_RATE_BANDS,
                $occurrence->workDate,
                $entry,
                $exit,
                $this->minutes($minuteEntry, $minuteExit),
                0,
                new BandSplit,
                collect(),
                collect(),
                $isHoliday,
            );
        }

        $scheduledStart = $occurrence->scheduledStart;
        $scheduledEnd = $occurrence->scheduledEnd;
        $scheduledMinutes = 0;
        $scheduledRates = new BandSplit;
        $deficits = collect();
        $overtimeCandidates = collect();

        if ($scheduledStart !== null && $scheduledEnd !== null) {
            $overlapStart = $minuteEntry->max($scheduledStart);
            $overlapEnd = $minuteExit->min($scheduledEnd);
            $scheduledMinutes = $this->minutes($overlapStart, $overlapEnd);
            $scheduledRates = $this->ratesFor($occurrence, $overlapStart, $overlapEnd, false, $isHoliday);
            $fingerprint = $this->fingerprint($occurrence, $isHoliday);

            if ($minuteEntry->gt($scheduledStart)) {
                $deficitEnd = $minuteEntry->min($scheduledEnd);

                if ($this->minutes($scheduledStart, $deficitEnd) > 0) {
                    $deficits->push(new AttendanceSegment(
                        'late_arrival',
                        $scheduledStart,
                        $deficitEnd,
                        $fingerprint,
                        $this->ratesFor($occurrence, $scheduledStart, $deficitEnd, false, $isHoliday),
                    ));
                }
            }

            if ($minuteExit->lt($scheduledEnd)) {
                $deficitStart = $minuteExit->max($scheduledStart);

                if ($this->minutes($deficitStart, $scheduledEnd) > 0) {
                    $deficits->push(new AttendanceSegment(
                        'early_departure',
                        $deficitStart,
                        $scheduledEnd,
                        $fingerprint,
                        $this->ratesFor($occurrence, $deficitStart, $scheduledEnd, false, $isHoliday),
                    ));
                }
            }

            if ($minuteEntry->lt($scheduledStart)) {
                $candidateEnd = $minuteExit->min($scheduledStart);

                if ($this->minutes($minuteEntry, $candidateEnd) > 0) {
                    $overtimeCandidates->push(new AttendanceSegment(
                        'pre_shift',
                        $minuteEntry,
                        $candidateEnd,
                        $fingerprint,
                        $this->ratesFor($occurrence, $minuteEntry, $candidateEnd, true, $isHoliday),
                    ));
                }
            }

            if ($minuteExit->gt($scheduledEnd)) {
                $candidateStart = $minuteEntry->max($scheduledEnd);

                if ($this->minutes($candidateStart, $minuteExit) > 0) {
                    $overtimeCandidates->push(new AttendanceSegment(
                        'post_shift',
                        $candidateStart,
                        $minuteExit,
                        $fingerprint,
                        $this->ratesFor($occurrence, $candidateStart, $minuteExit, true, $isHoliday),
                    ));
                }
            }
        } elseif ($this->minutes($minuteEntry, $minuteExit) > 0) {
            $overtimeCandidates->push(new AttendanceSegment(
                'non_working',
                $minuteEntry,
                $minuteExit,
                $this->fingerprint($occurrence, $isHoliday),
                $this->ratesFor($occurrence, $minuteEntry, $minuteExit, true, $isHoliday),
            ));
        }

        return new AttendanceShiftAnalysis(
            status: $occurrence->status,
            workDate: $occurrence->workDate,
            entryAt: $entry,
            exitAt: $exit,
            workedMinutes: $this->minutes($minuteEntry, $minuteExit),
            scheduledMinutes: $scheduledMinutes,
            scheduledRates: $scheduledRates,
            deficits: $deficits,
            overtimeCandidates: $overtimeCandidates,
            isHoliday: $isHoliday,
        );
    }

    private function minutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $end->gt($start) ? (int) floor($start->diffInSeconds($end) / 60) : 0;
    }

    private function fingerprint(ShiftOccurrence $occurrence, bool $isHoliday): string
    {
        return hash('sha256', implode('|', [
            $occurrence->assignment?->id,
            $occurrence->schedule?->id,
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
        ]));
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
