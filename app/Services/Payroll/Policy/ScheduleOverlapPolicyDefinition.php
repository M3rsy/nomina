<?php

namespace App\Services\Payroll\Policy;

final readonly class ScheduleOverlapPolicyDefinition extends PayrollPolicyDefinition
{
    private const RATE_BANDS = [
        [0, 360, 'extra75'],
        [360, 840, 'ordinary'],
        [840, 1080, 'extra25'],
        [1080, 1440, 'extra50'],
    ];

    private const HOLIDAY_OR_SUNDAY_BUCKET = 'extra100';

    private const SUNDAY = 0;

    private const SATURDAY_ORDINARY_CANDIDATE_BUCKET = 'extra25';

    private const SATURDAY = 6;

    private const MINIMUM_SEGMENT_MINUTES = 1;

    public string $key;

    public string $calculationMode;

    public string $definitionHash;

    public function __construct()
    {
        $this->key = 'schedule-overlap-v1';
        $this->calculationMode = 'schedule_overlap';
        $this->definitionHash = hash('sha256', json_encode([
            $this->key,
            $this->calculationMode,
            self::SECONDS_PER_MINUTE,
            self::RATE_BANDS,
            self::HOLIDAY_OR_SUNDAY_BUCKET,
            self::SUNDAY,
            self::SATURDAY_ORDINARY_CANDIDATE_BUCKET,
            self::SATURDAY,
            self::MINIMUM_SEGMENT_MINUTES,
        ], JSON_THROW_ON_ERROR));
    }

    public function rateBucketAt(
        int $minuteOfDay,
        bool $isHoliday,
        int $dayOfWeek,
        bool $isOvertimeCandidate,
    ): string {
        if ($minuteOfDay < 0 || $minuteOfDay >= 1440) {
            throw new \InvalidArgumentException('Minute of day must be between 0 and 1439.');
        }

        if ($isHoliday || $dayOfWeek === self::SUNDAY) {
            return self::HOLIDAY_OR_SUNDAY_BUCKET;
        }

        foreach (self::RATE_BANDS as [$start, $end, $bucket]) {
            if ($minuteOfDay >= $start && $minuteOfDay < $end) {
                return $isOvertimeCandidate && $dayOfWeek === self::SATURDAY && $bucket === 'ordinary'
                    ? self::SATURDAY_ORDINARY_CANDIDATE_BUCKET
                    : $bucket;
            }
        }

        throw new \InvalidArgumentException('Minute of day must be between 0 and 1439.');
    }

    public function shouldCreateScheduledDeficit(
        int $missingScheduledMinutes,
        bool $isHoliday,
        int $dayOfWeek,
    ): bool {
        return $missingScheduledMinutes >= self::MINIMUM_SEGMENT_MINUTES;
    }

    public function shouldCreateOvertimeCandidate(
        int $outsideScheduleMinutes,
        bool $isHoliday,
        int $dayOfWeek,
    ): bool {
        return $outsideScheduleMinutes >= self::MINIMUM_SEGMENT_MINUTES;
    }

    public function shouldCreateFullDayAbsence(
        bool $isHoliday,
        bool $isWorkingDay,
        int $scheduledMinutes,
    ): bool {
        return ! $isHoliday
            && $isWorkingDay
            && $scheduledMinutes >= self::MINIMUM_SEGMENT_MINUTES;
    }
}
