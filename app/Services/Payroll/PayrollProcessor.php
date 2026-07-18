<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\JustifiedAbsence;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\RawMark;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollProcessor
{
    public function __construct(
        private PayrollCalculator $calculator,
    ) {
    }

    public function processPayPeriod(PayPeriod $payPeriod): PayrollProcessReport
    {
        if ($payPeriod->status !== 'ready') {
            throw new InvalidArgumentException('PayPeriod must be in ready state to process.');
        }

        $report = new PayrollProcessReport;

        DB::transaction(function () use ($payPeriod, &$report) {
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
                    $marks = $this->marksForDay($companyId, $payPeriod->id, $employee->id, $date);
                    $absence = $this->absenceForDay($companyId, $payPeriod->id, $employee->id, $date);

                    $result = $this->calculator->calculateForDay(
                        $payPeriod->company,
                        $employee,
                        $date,
                        $marks,
                        $absence,
                    );

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

        return $report;
    }

    private function marksForDay(int $companyId, int $payPeriodId, int $employeeId, CarbonImmutable $date): \Illuminate\Support\Collection
    {
        return RawMark::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('pay_period_id', $payPeriodId)
            ->where('employee_id', $employeeId)
            ->whereDate('event_at', $date->toDateString())
            ->get();
    }

    private function absenceForDay(int $companyId, int $payPeriodId, int $employeeId, CarbonImmutable $date): ?JustifiedAbsence
    {
        return JustifiedAbsence::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('pay_period_id', $payPeriodId)
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date->toDateString())
            ->first();
    }

    private function shouldSkip(PayrollDayResult $result): bool
    {
        // Non-working days without marks or absences produce a skip marker.
        if (($result->metadata['skip'] ?? false) === true) {
            return true;
        }

        return false;
    }

    private function storeResult(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonImmutable $date,
        PayrollDayResult $result,
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
            'entry_at' => $result->entryAt,
            'exit_at' => $result->exitAt,
            'worked_hours' => $result->workedHours,
            'ordinary_hours' => $result->ordinaryHours,
            'extra_25_hours' => $result->extra25Hours,
            'extra_50_hours' => $result->extra50Hours,
            'extra_75_hours' => $result->extra75Hours,
            'extra_100_hours' => $result->extra100Hours,
            'is_absence' => $result->isAbsence,
            'is_justified' => $result->isJustified,
            'unjustified' => $result->unjustified,
            'notes' => $result->notes,
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
