<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use Illuminate\Support\Collection;

readonly class PayrollShiftReview
{
    /**
     * @param  Collection<int, OvertimeDecision>  $currentDecisions
     * @param  Collection<int, AttendanceException>  $currentExceptions
     */
    public function __construct(
        public Employee $employee,
        public ShiftOccurrence $occurrence,
        public AttendanceShiftAnalysis $analysis,
        public Collection $currentDecisions,
        public Collection $currentExceptions,
    ) {}

    public function decisionFor(AttendanceSegment $candidate): ?OvertimeDecision
    {
        return $this->currentDecisions->firstWhere('candidate_key', $candidate->key);
    }

    public function exceptionFor(AttendanceSegment $deficit): ?AttendanceException
    {
        return $this->currentExceptions->firstWhere('deficit_key', $deficit->key);
    }
}
