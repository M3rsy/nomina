<?php

namespace App\Services\Payroll;

use RuntimeException;

class PayrollProcessingBlocked extends RuntimeException
{
    /** @param array<int, array{employee_id:int,work_date:string,blockers:mixed}> $blockers */
    public function __construct(public array $blockers)
    {
        parent::__construct('Payroll processing is blocked by unresolved attendance review.');
    }
}
