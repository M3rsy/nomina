<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\PayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PayrollReadinessChecker
{
    public function __construct(private PayrollShiftEvaluationResolver $evaluationResolver) {}

    /** @return Collection<int, array{employee_id:int,employee_name:string,employee_external_id:string,work_date:string,code:string,candidate_key?:string}> */
    public function blockers(PayPeriod $payPeriod): Collection
    {
        $blockers = collect();
        $employees = Employee::withoutCompanyScope()
            ->where('company_id', $payPeriod->company_id)
            ->get();
        $start = CarbonImmutable::parse($payPeriod->start_date);
        $end = CarbonImmutable::parse($payPeriod->end_date);

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            foreach ($employees as $employee) {
                $evaluation = $this->evaluationResolver->resolve($payPeriod, $employee, $date);

                foreach ($evaluation->blockers as $blocker) {
                    $blockers->push([
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->full_name,
                        'employee_external_id' => $employee->external_id,
                        'work_date' => $date->toDateString(),
                        ...$blocker,
                    ]);
                }
            }
        }

        return $blockers;
    }
}
