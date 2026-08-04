<?php

namespace App\Services\Attendance;

use App\Services\Payroll\BandSplit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

readonly class AttendanceShiftAnalysis
{
    public const INVALID_INTERVAL = 'invalid_interval';

    public const INVALID_RATE_BANDS = 'invalid_rate_bands';

    public const UNSUPPORTED_PAYROLL_POLICY = 'unsupported_payroll_policy';

    /**
     * @param  Collection<int, AttendanceSegment>  $deficits
     * @param  Collection<int, AttendanceSegment>  $overtimeCandidates
     */
    public function __construct(
        public string $status,
        public CarbonImmutable $workDate,
        public ?CarbonImmutable $entryAt,
        public ?CarbonImmutable $exitAt,
        public int $workedMinutes,
        public int $scheduledMinutes,
        public BandSplit $scheduledRates,
        public Collection $deficits,
        public Collection $overtimeCandidates,
        public bool $isHoliday = false,
        public ?int $publicationId = null,
        public ?string $payrollPolicyKey = null,
        public Collection $variations = new Collection,
        public int $excludedTransferMinutes = 0,
    ) {}
}
