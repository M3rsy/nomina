<?php

namespace App\Services\Attendance;

use App\Services\Payroll\BandSplit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

readonly class PayrollShiftEvaluation
{
    public const BLOCKED = 'blocked';

    public const PROCESSABLE = 'processable';

    public const SKIP = 'skip';

    /**
     * @param  Collection<int, array{code:string,candidate_key?:string}>  $blockers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public CarbonImmutable $workDate,
        public ?CarbonImmutable $entryAt = null,
        public ?CarbonImmutable $exitAt = null,
        public int $workedMinutes = 0,
        public int $scheduledMinutes = 0,
        public int $recognizedMinutes = 0,
        public int $detectedOvertimeMinutes = 0,
        public int $approvedOvertimeMinutes = 0,
        public BandSplit $payableRates = new BandSplit,
        public bool $isAbsence = false,
        public bool $isJustified = false,
        public bool $unjustified = false,
        public Collection $blockers = new Collection,
        public array $metadata = [],
    ) {}
}
