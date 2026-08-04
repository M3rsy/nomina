<?php

namespace App\Services\Payroll;

use Carbon\CarbonInterface;

final readonly class PayrollContextTargets
{
    public array $payPeriodIds;

    public array $profileIds;

    public array $publicationIds;

    public array $employeeIds;

    public array $assignmentIds;

    public array $rawMarkIds;

    /**
     * @param  list<int>  $payPeriodIds
     * @param  list<int>  $profileIds
     * @param  list<int>  $publicationIds
     * @param  list<int>  $employeeIds
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $rawMarkIds
     */
    public function __construct(
        array $payPeriodIds = [],
        array $profileIds = [],
        array $publicationIds = [],
        array $employeeIds = [],
        array $assignmentIds = [],
        array $rawMarkIds = [],
        public CarbonInterface|string|null $holidayStart = null,
        public CarbonInterface|string|null $holidayEnd = null,
    ) {
        $this->payPeriodIds = self::normalizeIds($payPeriodIds);
        $this->profileIds = self::normalizeIds($profileIds);
        $this->publicationIds = self::normalizeIds($publicationIds);
        $this->employeeIds = self::normalizeIds($employeeIds);
        $this->assignmentIds = self::normalizeIds($assignmentIds);
        $this->rawMarkIds = self::normalizeIds($rawMarkIds);
    }

    /** @param list<int> $ids @return list<int> */
    private static function normalizeIds(array $ids): array
    {
        return collect($ids)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
    }
}
