<?php

namespace App\Services\Payroll;

final readonly class CreatedUnknownEmployee
{
    public function __construct(
        public int $employeeId,
        public int $assignedMarks,
    ) {}
}
