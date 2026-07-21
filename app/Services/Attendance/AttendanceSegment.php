<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

readonly class AttendanceSegment
{
    public int $minutes;

    public string $key;

    public function __construct(
        public string $kind,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $fingerprint,
    ) {
        $seconds = $start->diffInSeconds($end);

        if ($end->lte($start) || $seconds < 60) {
            throw new InvalidArgumentException('Attendance segment must contain at least one whole minute.');
        }

        $this->minutes = (int) floor($seconds / 60);
        $this->key = hash('sha256', implode('|', [$kind, $start->toIso8601String(), $end->toIso8601String(), $fingerprint]));
    }
}
