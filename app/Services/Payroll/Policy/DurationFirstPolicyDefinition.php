<?php

namespace App\Services\Payroll\Policy;

final readonly class DurationFirstPolicyDefinition extends PayrollPolicyDefinition
{
    private const ORDINARY_QUOTA_MINUTES = 8 * 60;

    private const SATURDAY = 6;

    private const SATURDAY_PHYSICAL_QUOTA_MINUTES = 4 * 60;

    private const SATURDAY_RECOGNIZED_MINUTES = 4 * 60;

    private const SUNDAY = 0;

    private const OVERRIDE_BUCKET = 'extra100';

    private const OVERTIME_BANDS = [
        [0, 360, 'extra75'],
        [360, 1080, 'extra25'],
        [1080, 1440, 'extra50'],
    ];

    private const TRANSFER_MINIMUM_OVERTIME_MINUTES = 60;

    private const TRANSFER_RESIDUAL_MAXIMUM_MINUTES = 30;

    private const ENTRY_VARIATION_GRACE_MINUTES = 20;

    public string $key;

    public string $calculationMode;

    public string $definitionHash;

    public function __construct()
    {
        $this->key = 'duration-first-v2';
        $this->calculationMode = 'duration_first';
        $this->definitionHash = hash('sha256', json_encode([
            $this->key,
            $this->calculationMode,
            parent::SECONDS_PER_MINUTE,
            self::ORDINARY_QUOTA_MINUTES,
            self::SATURDAY,
            self::SATURDAY_PHYSICAL_QUOTA_MINUTES,
            self::SATURDAY_RECOGNIZED_MINUTES,
            self::SUNDAY,
            self::OVERRIDE_BUCKET,
            self::OVERTIME_BANDS,
            self::TRANSFER_MINIMUM_OVERTIME_MINUTES,
            self::TRANSFER_RESIDUAL_MAXIMUM_MINUTES,
            self::ENTRY_VARIATION_GRACE_MINUTES,
        ], JSON_THROW_ON_ERROR));
    }

    public function ordinaryMinutes(int $workedMinutes, bool $isHoliday, int $dayOfWeek): int
    {
        if ($this->isHolidayOrSunday($isHoliday, $dayOfWeek)) {
            return 0;
        }

        if ($dayOfWeek === self::SATURDAY) {
            return min($workedMinutes, self::SATURDAY_PHYSICAL_QUOTA_MINUTES)
                + self::SATURDAY_RECOGNIZED_MINUTES;
        }

        return min($workedMinutes, self::ORDINARY_QUOTA_MINUTES);
    }

    public function overtimeStartMinutes(int $workedMinutes, bool $isHoliday, int $dayOfWeek): int
    {
        if ($this->isHolidayOrSunday($isHoliday, $dayOfWeek)) {
            return 0;
        }

        return min($workedMinutes, $this->overtimeQuotaMinutes($dayOfWeek));
    }

    public function overrideRateBucket(bool $isHoliday, int $dayOfWeek): ?string
    {
        return $this->isHolidayOrSunday($isHoliday, $dayOfWeek) ? self::OVERRIDE_BUCKET : null;
    }

    public function extra100Minutes(int $workedMinutes, bool $isHoliday, int $dayOfWeek): int
    {
        return $this->overrideRateBucket($isHoliday, $dayOfWeek) === self::OVERRIDE_BUCKET
            ? $workedMinutes
            : 0;
    }

    public function shortfallMinutes(
        int $workedMinutes,
        bool $isHoliday,
        int $dayOfWeek,
        bool $isWorkingDay,
    ): int {
        if ($this->isHolidayOrSunday($isHoliday, $dayOfWeek) || ! $isWorkingDay) {
            return 0;
        }

        return max(0, $this->overtimeQuotaMinutes($dayOfWeek) - $workedMinutes);
    }

    public function shouldCreateOvertimeCandidate(
        int $workedMinutes,
        bool $isHoliday,
        int $dayOfWeek,
    ): bool {
        return ! $this->isHolidayOrSunday($isHoliday, $dayOfWeek)
            && $workedMinutes > $this->overtimeQuotaMinutes($dayOfWeek);
    }

    public function excludedTransferMinutes(
        int $workedMinutes,
        bool $isHoliday,
        int $dayOfWeek,
    ): int {
        if ($this->isHolidayOrSunday($isHoliday, $dayOfWeek)) {
            return 0;
        }

        $overtimeMinutes = max(0, $workedMinutes - $this->overtimeQuotaMinutes($dayOfWeek));
        $residualMinutes = $overtimeMinutes % 60;

        return $overtimeMinutes >= self::TRANSFER_MINIMUM_OVERTIME_MINUTES
            && $residualMinutes >= 1
            && $residualMinutes <= self::TRANSFER_RESIDUAL_MAXIMUM_MINUTES
                ? $residualMinutes
                : 0;
    }

    public function recognizedOvertimeMinutes(
        int $workedMinutes,
        bool $isHoliday,
        int $dayOfWeek,
    ): int {
        if (! $this->shouldCreateOvertimeCandidate($workedMinutes, $isHoliday, $dayOfWeek)) {
            return 0;
        }

        return $workedMinutes - $this->overtimeQuotaMinutes($dayOfWeek)
            - $this->excludedTransferMinutes($workedMinutes, $isHoliday, $dayOfWeek);
    }

    public function overtimeBucketAt(int $minuteOfDay): string
    {
        foreach (self::OVERTIME_BANDS as [$start, $end, $bucket]) {
            if ($minuteOfDay >= $start && $minuteOfDay < $end) {
                return $bucket;
            }
        }

        throw new \InvalidArgumentException('Minute of day must be between 0 and 1439.');
    }

    public function shouldRecordEntryVariation(
        int $workedMinutes,
        ?int $minutesAfterScheduledStart,
        bool $isHoliday,
        int $dayOfWeek,
    ): bool {
        return ! $this->isHolidayOrSunday($isHoliday, $dayOfWeek)
            && $workedMinutes >= $this->overtimeQuotaMinutes($dayOfWeek)
            && $minutesAfterScheduledStart !== null
            && $minutesAfterScheduledStart > self::ENTRY_VARIATION_GRACE_MINUTES;
    }

    private function overtimeQuotaMinutes(int $dayOfWeek): int
    {
        return $dayOfWeek === self::SATURDAY
            ? self::SATURDAY_PHYSICAL_QUOTA_MINUTES
            : self::ORDINARY_QUOTA_MINUTES;
    }

    private function isHolidayOrSunday(bool $isHoliday, int $dayOfWeek): bool
    {
        return $isHoliday || $dayOfWeek === self::SUNDAY;
    }
}
