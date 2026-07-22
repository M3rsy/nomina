<?php

namespace App\Services\Attendance;

use App\Models\JustifiedAbsence;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class FullDayAbsenceSnapshot
{
    /**
     * @param  array{ordinary: int, extra25: int, extra50: int, extra75: int, extra100: int}  $rateMinutes
     */
    private function __construct(
        public readonly string $fingerprint,
        public readonly CarbonImmutable $scheduledStart,
        public readonly CarbonImmutable $scheduledEnd,
        public readonly int $scheduledMinutes,
        public readonly array $rateMinutes,
    ) {}

    public static function from(ShiftOccurrence $occurrence, AttendanceShiftAnalysis $analysis): self
    {
        $rates = $analysis->scheduledRates;

        if ($analysis->status !== ShiftOccurrence::NO_MARKS
            || $analysis->isHoliday
            || ! $occurrence->schedule?->is_working_day
            || $occurrence->scheduledStart === null
            || $occurrence->scheduledEnd === null
            || $analysis->scheduledMinutes <= 0
            || $rates->totalMinutes() !== $analysis->scheduledMinutes) {
            throw new InvalidArgumentException('La falta debe corresponder a una jornada programada completa y vigente.');
        }

        $rateMinutes = [
            'ordinary' => $rates->ordinaryMinutes,
            'extra25' => $rates->extra25Minutes,
            'extra50' => $rates->extra50Minutes,
            'extra75' => $rates->extra75Minutes,
            'extra100' => $rates->extra100Minutes,
        ];
        $payload = [
            'assignment_id' => $occurrence->assignment?->id,
            'schedule_id' => $occurrence->schedule->id,
            'work_date' => $occurrence->workDate->toDateString(),
            'scheduled_start' => $occurrence->scheduledStart->toIso8601String(),
            'scheduled_end' => $occurrence->scheduledEnd->toIso8601String(),
            'base_ordinary_hours' => $occurrence->schedule->base_ordinary_hours,
            'banding_json' => $occurrence->schedule->banding_json,
            'rate_minutes' => $rateMinutes,
        ];

        return new self(
            hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            $occurrence->scheduledStart,
            $occurrence->scheduledEnd,
            $analysis->scheduledMinutes,
            $rateMinutes,
        );
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'schedule_fingerprint' => $this->fingerprint,
            'scheduled_start' => $this->scheduledStart,
            'scheduled_end' => $this->scheduledEnd,
            'scheduled_minutes' => $this->scheduledMinutes,
            'rate_minutes' => $this->rateMinutes,
        ];
    }

    /** @return array<string, mixed> */
    public function auditValues(): array
    {
        return [
            'schedule_fingerprint' => $this->fingerprint,
            'scheduled_start' => $this->scheduledStart->toIso8601String(),
            'scheduled_end' => $this->scheduledEnd->toIso8601String(),
            'scheduled_minutes' => $this->scheduledMinutes,
            'rate_minutes' => $this->rateMinutes,
        ];
    }

    public function matches(JustifiedAbsence $absence): bool
    {
        return $absence->schedule_fingerprint === $this->fingerprint
            && $absence->scheduled_start?->equalTo($this->scheduledStart)
            && $absence->scheduled_end?->equalTo($this->scheduledEnd)
            && $absence->scheduled_minutes === $this->scheduledMinutes
            && $absence->rate_minutes === $this->rateMinutes;
    }
}
