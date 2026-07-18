<?php

namespace App\Services\Payroll;

readonly class BandSplit
{
    /**
     * @param  float  $ordinaryMinutes  Minutes in the 06:00-14:00 ordinary band.
     * @param  float  $extra25Minutes  Minutes in the 14:00-18:00 extra 25% band.
     * @param  float  $extra50Minutes  Minutes in the 18:00-00:00 extra 50% band.
     * @param  float  $extra75Minutes  Minutes in the 00:00-06:00 extra 75% band.
     */
    public function __construct(
        public float $ordinaryMinutes = 0,
        public float $extra25Minutes = 0,
        public float $extra50Minutes = 0,
        public float $extra75Minutes = 0,
    ) {
    }

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

    public function totalMinutes(): float
    {
        return $this->ordinaryMinutes
            + $this->extra25Minutes
            + $this->extra50Minutes
            + $this->extra75Minutes;
    }

    public function totalHours(): float
    {
        return $this->totalMinutes() / 60;
    }
}
