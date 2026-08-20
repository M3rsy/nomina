<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\AttendanceVariationAcknowledgement;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class PayrollShiftEvaluationResolver
{
    public function __construct(
        private ShiftOccurrenceResolver $occurrenceResolver,
        private AttendanceShiftAnalysisResolver $analysisResolver,
        private PayrollShiftEvaluator $shiftEvaluator,
        private HolidayCalendar $holidayCalendar,
        private AttendanceDecisionMatcher $decisionMatcher,
    ) {}

    public function resolve(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
        ?HolidayCalendarContext $calendarContext = null,
        ?PayrollPeriodSnapshotData $snapshot = null,
    ): PayrollShiftEvaluation {
        $review = $this->review($payPeriod, $employee, $workDate, $calendarContext, $snapshot);

        return $this->shiftEvaluator->evaluate(
            $review->occurrence,
            $review->analysis,
            $review->currentDecisions,
            $review->currentExceptions,
        );
    }

    public function review(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
        ?HolidayCalendarContext $calendarContext = null,
        ?PayrollPeriodSnapshotData $snapshot = null,
    ): PayrollShiftReview {
        $date = CarbonImmutable::parse($workDate)->startOfDay();

        if ($employee->company_id !== $payPeriod->company_id
            || $date->lt($payPeriod->start_date->startOfDay())
            || $date->gt($payPeriod->end_date)) {
            throw new InvalidArgumentException('Employee and work date must belong to the payroll period.');
        }

        $calendarContext ??= $this->holidayCalendar->capture($payPeriod->company, $date, $date);
        $occurrence = $snapshot === null
            ? $this->occurrenceResolver->resolve($employee, $date)
            : $this->occurrenceResolver->resolveFromSnapshot($employee, $date, $snapshot);
        $analysis = $this->analysisResolver->resolve($employee, $occurrence, $calendarContext, $snapshot);
        $decisions = $snapshot?->decisions($employee, $date)
            ?? OvertimeDecision::withoutCompanyScope()
                ->where('company_id', $payPeriod->company_id)
                ->where('pay_period_id', $payPeriod->id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $date->toDateString())
                ->current()
                ->with('decider')
                ->get();
        $exceptions = $snapshot?->exceptions($employee, $date)
            ?? AttendanceException::withoutCompanyScope()
                ->where('company_id', $payPeriod->company_id)
                ->where('pay_period_id', $payPeriod->id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $date->toDateString())
                ->current()
                ->with('decider')
                ->get();
        $variationAcknowledgements = $snapshot?->variationAcknowledgements($employee, $date)
            ?? AttendanceVariationAcknowledgement::withoutCompanyScope()
                ->where('company_id', $payPeriod->company_id)
                ->where('pay_period_id', $payPeriod->id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $date->toDateString())
                ->with('acknowledger')
                ->get();

        return new PayrollShiftReview(
            $employee,
            $occurrence,
            $analysis,
            $decisions,
            $exceptions,
            $variationAcknowledgements,
            $this->decisionMatcher,
        );
    }
}
