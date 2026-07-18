<?php

namespace App\Services\Payroll;

use Carbon\CarbonInterface;

readonly class PayrollDayResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?CarbonInterface $entryAt = null,
        public ?CarbonInterface $exitAt = null,
        public float $workedHours = 0.0,
        public float $ordinaryHours = 0.0,
        public int $extra25Hours = 0,
        public int $extra50Hours = 0,
        public int $extra75Hours = 0,
        public int $extra100Hours = 0,
        public bool $isAbsence = false,
        public bool $isJustified = false,
        public bool $unjustified = false,
        public ?string $notes = null,
        public array $metadata = [],
    ) {
    }

    public function totalPaidHours(): float
    {
        return $this->ordinaryHours
            + $this->extra25Hours
            + $this->extra50Hours
            + $this->extra75Hours
            + $this->extra100Hours;
    }
}
