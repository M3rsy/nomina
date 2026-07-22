<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\JustifiedAbsence;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Services\PayrollRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class PayrollShiftEvaluationResolver
{
    public function __construct(
        private ShiftOccurrenceResolver $occurrenceResolver,
        private AttendanceShiftAnalyzer $shiftAnalyzer,
        private PayrollShiftEvaluator $shiftEvaluator,
        private PayrollRules $rules,
    ) {}

    public function resolve(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
    ): PayrollShiftEvaluation {
        $review = $this->review($payPeriod, $employee, $workDate);
        $date = $review->occurrence->workDate;
        $absence = JustifiedAbsence::withoutCompanyScope()
            ->where('company_id', $payPeriod->company_id)
            ->where('pay_period_id', $payPeriod->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        return $this->shiftEvaluator->evaluate(
            $review->occurrence,
            $review->analysis,
            $review->currentDecisions,
            $absence,
        );
    }

    public function review(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
    ): PayrollShiftReview {
        $date = CarbonImmutable::parse($workDate)->startOfDay();

        if ($employee->company_id !== $payPeriod->company_id
            || $date->lt($payPeriod->start_date->startOfDay())
            || $date->gt($payPeriod->end_date)) {
            throw new InvalidArgumentException('Employee and work date must belong to the payroll period.');
        }

        $occurrence = $this->occurrenceResolver->resolve($employee, $date);
        $analysis = $this->shiftAnalyzer->analyze(
            $occurrence,
            $this->rules->isHoliday($payPeriod->company, $date),
        );
        $decisions = OvertimeDecision::withoutCompanyScope()
            ->where('company_id', $payPeriod->company_id)
            ->where('pay_period_id', $payPeriod->id)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->current()
            ->with('decider')
            ->get();

        return new PayrollShiftReview($employee, $occurrence, $analysis, $decisions);
    }
}
