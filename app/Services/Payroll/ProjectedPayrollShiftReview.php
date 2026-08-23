<?php

namespace App\Services\Payroll;

use stdClass;

class ProjectedPayrollShiftReview extends stdClass
{
    /** @var array<string, object|null> */
    public array $resolutions = [];

    public function decisionFor(object $segment): ?object
    {
        return $this->resolutions[$segment->key] ?? null;
    }

    public function exceptionFor(object $segment): ?object
    {
        return $this->resolutions[$segment->key] ?? null;
    }
}
