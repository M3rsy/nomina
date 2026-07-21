<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\OvertimeDecision;
use Illuminate\Support\Collection;

readonly class PayrollShiftReview
{
    /** @param Collection<int, OvertimeDecision> $currentDecisions */
    public function __construct(
        public Employee $employee,
        public ShiftOccurrence $occurrence,
        public AttendanceShiftAnalysis $analysis,
        public Collection $currentDecisions,
    ) {}

    public function decisionFor(AttendanceSegment $candidate): ?OvertimeDecision
    {
        return $this->currentDecisions->firstWhere('candidate_key', $candidate->key);
    }
}
