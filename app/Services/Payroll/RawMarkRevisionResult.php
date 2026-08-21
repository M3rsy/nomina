<?php

namespace App\Services\Payroll;

final readonly class RawMarkRevisionResult
{
    public function __construct(public int $affectedMarks) {}
}
