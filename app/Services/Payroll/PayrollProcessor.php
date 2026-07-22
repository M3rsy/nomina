<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Services\Attendance\PayrollShiftEvaluation;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollProcessor
{
    public function __construct(
        private PayrollShiftEvaluationResolver $evaluationResolver,
        private PayPeriodRangeGuard $rangeGuard,
    ) {}

    public function processPayPeriod(PayPeriod $payPeriod): PayrollProcessReport
    {
        $report = new PayrollProcessReport;

        DB::transaction(function () use ($payPeriod, &$report) {
            $payPeriod = PayPeriod::withoutCompanyScope()
                ->whereKey($payPeriod->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payPeriod->status !== 'ready') {
                throw new InvalidArgumentException('PayPeriod must be in ready state to process.');
            }

            $this->rangeGuard->assertAvailable(
                $payPeriod->company_id,
                $payPeriod->start_date,
                $payPeriod->end_date,
                $payPeriod->id,
            );

            $payPeriod->status = 'processing';
            $payPeriod->save();

            $companyId = $payPeriod->company_id;
            $start = CarbonImmutable::parse($payPeriod->start_date);
            $end = CarbonImmutable::parse($payPeriod->end_date);

            $employees = Employee::withoutCompanyScope()
                ->where('company_id', $companyId)
                ->get();

            $rulesVersion = config('payroll.rules_version', '1');

            for ($date = $start->copy(); $date->lte($end); $date = $date->addDay()) {
                foreach ($employees as $employee) {
                    $result = $this->evaluationResolver->resolve($payPeriod, $employee, $date);

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

                    $this->storeResult($payPeriod, $employee, $date, $result, $rulesVersion, $report);
                    $report->daysProcessed++;
                }
            }

            $report->employeesProcessed = $employees->count();

            $payPeriod->status = 'processed';
            $payPeriod->save();
        });

        $payPeriod->refresh();

        return $report;
    }

    private function shouldSkip(PayrollShiftEvaluation $result): bool
    {
        return $result->status === PayrollShiftEvaluation::SKIP;
    }

    private function storeResult(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonImmutable $date,
        PayrollShiftEvaluation $result,
        string $rulesVersion,
        PayrollProcessReport $report,
    ): void {
        $existing = PayrollResult::withoutCompanyScope()
            ->where('company_id', $payPeriod->company_id)
            ->where('pay_period_id', $payPeriod->id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        $attributes = [
            'company_id' => $payPeriod->company_id,
            'pay_period_id' => $payPeriod->id,
            'employee_id' => $employee->id,
            'date' => $date->toDateString(),
            'employee_external_id' => $employee->external_id,
            'employee_name' => $employee->full_name,
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
            'metadata' => $result->metadata,
        ];

        if ($existing !== null) {
            $existing->update($attributes);
            $report->resultsUpdated++;
        } else {
            PayrollResult::create($attributes);
            $report->resultsInserted++;
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
