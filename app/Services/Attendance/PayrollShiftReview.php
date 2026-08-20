<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\AttendanceVariationAcknowledgement;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use Illuminate\Support\Collection;

readonly class PayrollShiftReview
{
    /**
     * @param  Collection<int, OvertimeDecision>  $currentDecisions
     * @param  Collection<int, AttendanceException>  $currentExceptions
     * @param  Collection<int, AttendanceVariationAcknowledgement>  $variationAcknowledgements
     */
    public function __construct(
        public Employee $employee,
        public ShiftOccurrence $occurrence,
        public AttendanceShiftAnalysis $analysis,
        public Collection $currentDecisions,
        public Collection $currentExceptions,
        public Collection $variationAcknowledgements,
        private AttendanceDecisionMatcher $decisionMatcher,
    ) {}

    public function decisionFor(AttendanceSegment $candidate): ?OvertimeDecision
    {
        return $this->decisionMatcher->overtime($this->currentDecisions, $candidate);
    }

    public function exceptionFor(AttendanceSegment $deficit): ?AttendanceException
    {
        return $this->decisionMatcher->exception($this->currentExceptions, $deficit);
    }

    public function acknowledgementFor(AttendanceVariation $variation): ?AttendanceVariationAcknowledgement
    {
        return $this->variationAcknowledgements->firstWhere('variation_key', $variation->key);
    }
}
