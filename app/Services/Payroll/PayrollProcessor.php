<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\PayrollPeriodSnapshotData;
use App\Services\Attendance\PayrollShiftEvaluation;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\Attendance\PayrollShiftEvaluator;
use App\Services\Attendance\PayrollShiftReview;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PayrollProcessor
{
    public function __construct(
        private PayrollShiftEvaluationResolver $evaluationResolver,
        private PayPeriodRangeGuard $rangeGuard,
        private PayrollContextLocker $contextLocker,
        private PayrollDaySnapshotWriter $snapshotWriter,
        private PayrollShiftEvaluator $shiftEvaluator,
    ) {}

    public function processPayPeriod(PayPeriod $payPeriod): PayrollProcessReport
    {
        $report = $this->contextLocker->within(
            $payPeriod->company_id,
            fn (): PayrollContextTargets => $this->targets($payPeriod),
            function (LockedPayrollContext $context) use ($payPeriod): PayrollProcessReport {
                $report = new PayrollProcessReport;
                $payPeriod = $context->payPeriod($payPeriod->id);
                $payPeriod->setRelation('company', $context->company);

                if ($payPeriod->status !== 'ready') {
                    throw new InvalidArgumentException('PayPeriod must be in ready state to process.');
                }

                $this->rangeGuard->assertAvailableLocked(
                    $context,
                    $payPeriod->start_date,
                    $payPeriod->end_date,
                    $payPeriod->id,
                );

                $payPeriod->status = 'processing';
                $payPeriod->save();

                $start = CarbonImmutable::parse($payPeriod->start_date);
                $end = CarbonImmutable::parse($payPeriod->end_date);
                $calendarContext = $context->holidayCalendar
                    ?? throw new InvalidArgumentException('Payroll context must include a holiday calendar.');
                $employees = $context->employees;
                $missingPaymentIdentity = $employees->first(fn (Employee $employee): bool => blank($employee->payment_code) || blank($employee->job_title));
                if ($missingPaymentIdentity !== null) {
                    throw new PayrollProcessingBlocked([[
                        'employee_id' => $missingPaymentIdentity->id,
                        'work_date' => $payPeriod->start_date->toDateString(),
                        'blockers' => [['code' => 'missing_payment_identity']],
                    ]]);
                }
                $snapshot = PayrollPeriodSnapshotData::capture($payPeriod, $employees);

                $rulesVersion = config('payroll.rules_version', '1');
                $resultGeneration = $payPeriod->authorized_result_generation ?? $payPeriod->current_result_generation;

                for ($date = $start->copy(); $date->lte($end); $date = $date->addDay()) {
                    foreach ($employees as $employee) {
                        $review = $this->evaluationResolver->review($payPeriod, $employee, $date, $calendarContext, $snapshot);
                        $result = $this->shiftEvaluator->evaluate(
                            $review->occurrence,
                            $review->analysis,
                            $review->currentDecisions,
                            $review->currentExceptions,
                        );

                        if ($result->status === PayrollShiftEvaluation::BLOCKED) {
                            throw new PayrollProcessingBlocked([[
                                'employee_id' => $employee->id,
                                'work_date' => $date->toDateString(),
                                'blockers' => $result->blockers->all(),
                            ]]);
                        }

                        if ($this->shouldSkip($result)) {
                            continue;
                        }

                        $this->storeResult(
                            $payPeriod,
                            $employee,
                            $date,
                            $review,
                            $result,
                            $calendarContext->generation($date),
                            $rulesVersion,
                            $resultGeneration,
                            $report,
                        );
                        $report->daysProcessed++;
                    }
                }

                $report->employeesProcessed = $employees->count();

                $payPeriod->status = 'processed';
                $payPeriod->current_result_generation = $resultGeneration;
                $payPeriod->authorized_result_generation = null;
                $payPeriod->save();

                return $report;
            },
        );

        $payPeriod->refresh();

        return $report;
    }

    private function targets(PayPeriod $requestedPeriod): PayrollContextTargets
    {
        $period = PayPeriod::withoutCompanyScope()->withTrashed()
            ->where('company_id', $requestedPeriod->company_id)
            ->findOrFail($requestedPeriod->id);
        $start = CarbonImmutable::parse($period->start_date)->subDays(2);
        $end = CarbonImmutable::parse($period->end_date)->addDays(2);
        $employeeIds = Employee::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->pluck('id');
        $assignments = EmployeeScheduleAssignment::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->get();
        $profileIds = $assignments->pluck('work_schedule_profile_id')->unique();
        $existingProfileIds = WorkScheduleProfile::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('id', $profileIds)
            ->pluck('id');

        if ($existingProfileIds->count() !== $profileIds->count()) {
            throw new PayrollProcessingBlocked([[
                'employee_id' => (int) $assignments->first()?->employee_id,
                'work_date' => $period->start_date->toDateString(),
                'blockers' => [['code' => 'missing_profile']],
            ]]);
        }

        $publicationIds = WorkScheduleProfilePublication::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('profile_id', $profileIds)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>', $start))
            ->pluck('id');
        $rawMarkIds = RawMark::withoutCompanyScope()
            ->where('company_id', $period->company_id)
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['valid', 'corrected'])
            ->where('event_at', '>=', $start->startOfDay())
            ->where('event_at', '<', $end->addDay()->startOfDay())
            ->pluck('id');

        return new PayrollContextTargets(
            payPeriodIds: PayPeriod::withoutCompanyScope()->withTrashed()
                ->where('company_id', $period->company_id)->pluck('id')->all(),
            profileIds: $profileIds->all(),
            publicationIds: $publicationIds->all(),
            employeeIds: $employeeIds->all(),
            assignmentIds: $assignments->pluck('id')->all(),
            rawMarkIds: $rawMarkIds->all(),
            holidayStart: $period->start_date,
            holidayEnd: $period->end_date,
        );
    }

    private function shouldSkip(PayrollShiftEvaluation $result): bool
    {
        return $result->status === PayrollShiftEvaluation::SKIP;
    }

    private function storeResult(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonImmutable $date,
        PayrollShiftReview $review,
        PayrollShiftEvaluation $result,
        int $calendarGeneration,
        string $rulesVersion,
        int $resultGeneration,
        PayrollProcessReport $report,
    ): void {
        $attributes = [
            'company_id' => $payPeriod->company_id,
            'pay_period_id' => $payPeriod->id,
            'result_generation' => $resultGeneration,
            'employee_id' => $employee->id,
            'date' => $date->toDateString(),
            'employee_external_id' => $employee->external_id,
            'employee_payment_code' => $employee->payment_code,
            'employee_name' => $employee->full_name,
            'employee_job_title' => $employee->job_title,
            'entry_at' => $result->entryAt,
            'exit_at' => $result->exitAt,
            'worked_hours' => $result->workedMinutes / 60,
            'ordinary_hours' => $result->payableRates->ordinaryHours(),
            'extra_25_hours' => $result->payableRates->extra25Hours(),
            'extra_50_hours' => $result->payableRates->extra50Hours(),
            'extra_75_hours' => $result->payableRates->extra75Hours(),
            'extra_100_hours' => $result->payableRates->extra100Hours(),
            'worked_minutes' => $result->workedMinutes,
            'scheduled_minutes' => $result->scheduledMinutes,
            'recognized_minutes' => $result->recognizedMinutes,
            'detected_overtime_minutes' => $result->detectedOvertimeMinutes,
            'approved_overtime_minutes' => $result->approvedOvertimeMinutes,
            'ordinary_minutes' => $result->payableRates->ordinaryMinutes,
            'extra_25_minutes' => $result->payableRates->extra25Minutes,
            'extra_50_minutes' => $result->payableRates->extra50Minutes,
            'extra_75_minutes' => $result->payableRates->extra75Minutes,
            'extra_100_minutes' => $result->payableRates->extra100Minutes,
            'is_absence' => $result->isAbsence,
            'is_justified' => $result->isJustified,
            'unjustified' => $result->unjustified,
            'notes' => $result->isJustified
                ? 'Justified absence: scheduled minutes paid.'
                : ($result->unjustified ? 'Unjustified absence on scheduled working day.' : null),
            'rules_version' => $rulesVersion,
            'calendar_generation' => $calendarGeneration,
            'metadata' => $result->metadata,
        ];

        $snapshot = PayrollDaySnapshot::capture($employee, $review, $result, $calendarGeneration, $rulesVersion);
        $stored = $this->snapshotWriter->write($attributes, $snapshot);

        if ($stored->wasRecentlyCreated) {
            $report->resultsInserted++;
        } else {
            $report->resultsReused++;
        }

        if ($result->isAbsence && $result->isJustified) {
            $report->justifiedAbsenceCount++;
        }

        if ($result->isAbsence && $result->unjustified) {
            $report->unjustifiedAbsenceCount++;
        }

        if ($result->isAbsence && ! $result->isJustified && ! $result->unjustified) {
            $report->missingSingleMarkCount++;
        }
    }
}
