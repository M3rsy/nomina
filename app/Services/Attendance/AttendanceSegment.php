<?php

namespace App\Services\Attendance;

use App\Services\Payroll\BandSplit;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

readonly class AttendanceSegment
{
    public int $minutes;

    public string $key;

    public function __construct(
        public string $kind,
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public string $fingerprint,
        public BandSplit $rateMinutes,
        ?int $minutes = null,
    ) {
        if ($start === null && $end === null) {
            if ($minutes === null || $minutes < 1 || $rateMinutes->totalMinutes() !== $minutes) {
                throw new InvalidArgumentException('A non-interval attendance fact must contain whole minutes.');
            }

            $this->minutes = $minutes;
            $this->key = hash('sha256', implode('|', [$kind, 'non-interval', $minutes, $fingerprint]));

            return;
        }

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('An attendance interval requires both boundaries.');
        }

        $seconds = $start->diffInSeconds($end);

        if ($end->lte($start) || $seconds < 60) {
            throw new InvalidArgumentException('Attendance segment must contain at least one whole minute.');
        }

        $this->minutes = (int) floor($seconds / 60);
        $this->key = hash('sha256', implode('|', [$kind, $start->toIso8601String(), $end->toIso8601String(), $fingerprint]));
    }
}
