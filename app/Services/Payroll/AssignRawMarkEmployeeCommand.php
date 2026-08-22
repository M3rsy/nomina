<?php

namespace App\Services\Payroll;

final readonly class AssignRawMarkEmployeeCommand
{
    public function __construct(
        public int $payPeriodId,
        public int $rawMarkId,
        public int $employeeId,
        public int $actorId,
        public string $reason,
        public bool $assignAll,
    ) {}
}
