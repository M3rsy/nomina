<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class HolidayCalendarContext
{
    /** @param array<string, array{is_holiday: bool, generation: int}> $dates */
    public function __construct(private array $dates) {}

    public function isHoliday(CarbonInterface|string $date): bool
    {
        return $this->date($date)['is_holiday'];
    }

    public function generation(CarbonInterface|string $date): int
    {
        return $this->date($date)['generation'];
    }

    /** @return array{is_holiday: bool, generation: int} */
    private function date(CarbonInterface|string $date): array
    {
        $key = CarbonImmutable::parse($date)->toDateString();

        return $this->dates[$key]
            ?? throw new InvalidArgumentException("Date [{$key}] is outside the captured holiday context.");
    }
}
