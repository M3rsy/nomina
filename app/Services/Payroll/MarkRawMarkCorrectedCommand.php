<?php

namespace App\Services\Payroll;

final readonly class MarkRawMarkCorrectedCommand
{
    public function __construct(
        public int $payPeriodId,
        public int $rawMarkId,
        public int $actorId,
        public string $reason,
    ) {}
}
