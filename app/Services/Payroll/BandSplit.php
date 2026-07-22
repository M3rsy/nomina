<?php

namespace App\Services\Payroll;

readonly class BandSplit
{
    /**
     * @param  int  $ordinaryMinutes  Minutes in the ordinary band.
     * @param  int  $extra25Minutes  Minutes in the extra 25% band.
     * @param  int  $extra50Minutes  Minutes in the extra 50% band.
     * @param  int  $extra75Minutes  Minutes in the extra 75% band.
     * @param  int  $extra100Minutes  Minutes in the extra 100% band.
     */
    public function __construct(
        public int $ordinaryMinutes = 0,
        public int $extra25Minutes = 0,
        public int $extra50Minutes = 0,
        public int $extra75Minutes = 0,
        public int $extra100Minutes = 0,
    ) {}

    public function ordinaryHours(): float
    {
        return $this->ordinaryMinutes / 60;
    }

    public function extra25Hours(): float
    {
        return $this->extra25Minutes / 60;
    }

    public function extra50Hours(): float
    {
        return $this->extra50Minutes / 60;
    }

    public function extra75Hours(): float
    {
        return $this->extra75Minutes / 60;
    }

    public function extra100Hours(): float
    {
        return $this->extra100Minutes / 60;
    }

    public function totalMinutes(): int
    {
        return $this->ordinaryMinutes
            + $this->extra25Minutes
            + $this->extra50Minutes
            + $this->extra75Minutes
            + $this->extra100Minutes;
    }

    public function totalHours(): float
    {
        return $this->totalMinutes() / 60;
    }

    public function plus(self $other): self
    {
        return new self(
            ordinaryMinutes: $this->ordinaryMinutes + $other->ordinaryMinutes,
            extra25Minutes: $this->extra25Minutes + $other->extra25Minutes,
            extra50Minutes: $this->extra50Minutes + $other->extra50Minutes,
            extra75Minutes: $this->extra75Minutes + $other->extra75Minutes,
            extra100Minutes: $this->extra100Minutes + $other->extra100Minutes,
        );
    }
}
