<?php

namespace App\Services\Payroll;

use Carbon\CarbonInterface;

final readonly class PayrollContextTargets
{
    /**
     * @param  list<int>  $payPeriodIds
     * @param  list<int>  $employeeIds
     * @param  list<int>  $rawMarkIds
     */
    public function __construct(
        public array $payPeriodIds = [],
        public array $employeeIds = [],
        public array $rawMarkIds = [],
        public CarbonInterface|string|null $holidayStart = null,
        public CarbonInterface|string|null $holidayEnd = null,
    ) {}
}
