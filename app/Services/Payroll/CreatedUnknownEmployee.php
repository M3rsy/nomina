<?php

namespace App\Services\Payroll;

use App\Models\Employee;

final readonly class CreatedUnknownEmployee
{
    public function __construct(
        public Employee $employee,
        public int $assignedMarks,
    ) {}
}
