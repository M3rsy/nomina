<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

readonly class AttendanceShiftAnalysis
{
    public const INVALID_INTERVAL = 'invalid_interval';

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
        public Collection $deficits,
        public Collection $overtimeCandidates,
    ) {}
}
