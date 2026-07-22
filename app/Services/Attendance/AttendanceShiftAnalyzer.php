<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

class AttendanceShiftAnalyzer
{
    public function analyze(ShiftOccurrence $occurrence): AttendanceShiftAnalysis
    {
        if ($occurrence->status !== ShiftOccurrence::RESOLVED) {
            return new AttendanceShiftAnalysis(
                $occurrence->status,
                $occurrence->workDate,
                null,
                null,
                0,
                0,
                collect(),
                collect(),
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
                collect(),
                collect(),
            );
        }

        $scheduledStart = $occurrence->scheduledStart;
        $scheduledEnd = $occurrence->scheduledEnd;
        $scheduledMinutes = 0;
        $deficits = collect();
        $overtimeCandidates = collect();

        if ($scheduledStart !== null && $scheduledEnd !== null) {
            $overlapStart = $entry->max($scheduledStart);
            $overlapEnd = $exit->min($scheduledEnd);
            $scheduledMinutes = $this->minutes($overlapStart, $overlapEnd);
            $fingerprint = $this->fingerprint($occurrence);

            if ($entry->gt($scheduledStart)) {
                $deficitEnd = $entry->min($scheduledEnd);

                if ($this->minutes($scheduledStart, $deficitEnd) > 0) {
                    $deficits->push(new AttendanceSegment(
                        'late_arrival',
                        $scheduledStart,
                        $deficitEnd,
                        $fingerprint,
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
                    ));
                }
            }
        } elseif ($this->minutes($entry, $exit) > 0) {
            $overtimeCandidates->push(new AttendanceSegment(
                'non_working',
                $entry,
                $exit,
                $this->fingerprint($occurrence),
            ));
        }

        return new AttendanceShiftAnalysis(
            status: $occurrence->status,
            workDate: $occurrence->workDate,
            entryAt: $entry,
            exitAt: $exit,
            workedMinutes: $this->minutes($entry, $exit),
            scheduledMinutes: $scheduledMinutes,
            deficits: $deficits,
            overtimeCandidates: $overtimeCandidates,
        );
    }

    private function minutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $end->gt($start) ? (int) floor($start->diffInSeconds($end) / 60) : 0;
    }

    private function fingerprint(ShiftOccurrence $occurrence): string
    {
        return hash('sha256', implode('|', [
            $occurrence->assignment?->id,
            $occurrence->schedule?->id,
            $occurrence->entryMark()?->id,
            $occurrence->entryMark()?->event_at?->toIso8601String(),
            $occurrence->exitMark()?->id,
            $occurrence->exitMark()?->event_at?->toIso8601String(),
        ]));
    }
}
