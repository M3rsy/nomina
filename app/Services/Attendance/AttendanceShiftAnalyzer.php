<?php

namespace App\Services\Attendance;

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
            return new AttendanceShiftAnalysis(
                $occurrence->status,
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

        $scheduledStart = $occurrence->scheduledStart;
        $scheduledEnd = $occurrence->scheduledEnd;
        $scheduledMinutes = 0;
        $scheduledRates = new BandSplit;
        $deficits = collect();
        $overtimeCandidates = collect();

        if ($scheduledStart !== null && $scheduledEnd !== null) {
            $overlapStart = $entry->max($scheduledStart);
            $overlapEnd = $exit->min($scheduledEnd);
            $scheduledMinutes = $this->minutes($overlapStart, $overlapEnd);
            $scheduledRates = $this->ratesFor($occurrence, $overlapStart, $overlapEnd, false, $isHoliday);
            $fingerprint = $this->fingerprint($occurrence, $isHoliday);

            if ($entry->gt($scheduledStart)) {
                $deficitEnd = $entry->min($scheduledEnd);

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

            if ($exit->lt($scheduledEnd)) {
                $deficitStart = $exit->max($scheduledStart);

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

            if ($entry->lt($scheduledStart)) {
                $candidateEnd = $exit->min($scheduledStart);

                if ($this->minutes($entry, $candidateEnd) > 0) {
                    $overtimeCandidates->push(new AttendanceSegment(
                        'pre_shift',
                        $entry,
                        $candidateEnd,
                        $fingerprint,
                        $this->ratesFor($occurrence, $entry, $candidateEnd, true, $isHoliday),
                    ));
                }
            }

            if ($exit->gt($scheduledEnd)) {
                $candidateStart = $entry->max($scheduledEnd);

                if ($this->minutes($candidateStart, $exit) > 0) {
                    $overtimeCandidates->push(new AttendanceSegment(
                        'post_shift',
                        $candidateStart,
                        $exit,
                        $fingerprint,
                        $this->ratesFor($occurrence, $candidateStart, $exit, true, $isHoliday),
                    ));
                }
            }
        } elseif ($this->minutes($entry, $exit) > 0) {
            $overtimeCandidates->push(new AttendanceSegment(
                'non_working',
                $entry,
                $exit,
                $this->fingerprint($occurrence, $isHoliday),
                $this->ratesFor($occurrence, $entry, $exit, true, $isHoliday),
            ));
        }

        return new AttendanceShiftAnalysis(
            status: $occurrence->status,
            workDate: $occurrence->workDate,
            entryAt: $entry,
            exitAt: $exit,
            workedMinutes: $this->minutes($entry, $exit),
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
            $occurrence->entryMark()?->id,
            $occurrence->entryMark()?->event_at?->toIso8601String(),
            $occurrence->exitMark()?->id,
            $occurrence->exitMark()?->event_at?->toIso8601String(),
        ]));
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
