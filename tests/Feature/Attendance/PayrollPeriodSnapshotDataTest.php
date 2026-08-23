<?php

use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\Attendance\PayrollPeriodSnapshotData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

test('period snapshot data resolves decisions by employee and work date', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $otherEmployee = Employee::factory()->forCompany($company)->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-21',
        'status' => 'uploaded',
    ]);
    $actor = User::factory()->forCompany($company)->create();

    $decision = OvertimeDecision::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-20',
    ]);
    OvertimeDecision::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-21',
    ]);
    OvertimeDecision::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $otherEmployee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-20',
    ]);
    $exception = AttendanceException::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-20',
    ]);
    AttendanceException::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $otherEmployee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-20',
    ]);

    $snapshot = PayrollPeriodSnapshotData::capture($period, new EloquentCollection([$employee, $otherEmployee]));
    $date = CarbonImmutable::parse('2026-07-20');

    expect($snapshot->decisions($employee, $date))->toHaveCount(1)
        ->and($snapshot->decisions($employee, $date)->sole()->is($decision))->toBeTrue()
        ->and($snapshot->exceptions($employee, $date))->toHaveCount(1)
        ->and($snapshot->exceptions($employee, $date)->sole()->is($exception))->toBeTrue();
});
