<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\CurrentCompany;
use Illuminate\Database\QueryException;

test('stores an immutable whole-candidate decision snapshot', function () {
    [$company, $period, $employee, $actor] = overtimeDecisionContext();

    $decision = OvertimeDecision::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-20',
        'candidate_key' => str_repeat('a', 64),
        'fingerprint' => str_repeat('b', 64),
        'segment_kind' => 'post_shift',
        'starts_at' => '2026-07-20 14:00:00',
        'ends_at' => '2026-07-20 14:30:00',
        'minutes' => 30,
        'rate_minutes' => ['ordinary' => 0, 'extra25' => 30, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
        'decision' => OvertimeDecision::APPROVED,
        'reason' => 'Servicio extraordinario autorizado',
    ]);

    expect($decision->work_date->toDateString())->toBe('2026-07-20')
        ->and($decision->minutes)->toBe(30)
        ->and($decision->rate_minutes['extra25'])->toBe(30)
        ->and($decision->company->is($company))->toBeTrue()
        ->and($decision->payPeriod->is($period))->toBeTrue()
        ->and($decision->employee->is($employee))->toBeTrue()
        ->and($decision->decider->is($actor))->toBeTrue();

    expect(fn () => $decision->update(['decision' => OvertimeDecision::REJECTED]))
        ->toThrow(LogicException::class)
        ->and(fn () => $decision->delete())
        ->toThrow(LogicException::class)
        ->and($decision->fresh()->decision)->toBe(OvertimeDecision::APPROVED);
});

test('keeps a linear append-only decision history', function () {
    [$company, $period, $employee, $actor] = overtimeDecisionContext();
    $snapshot = [
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-20',
        'candidate_key' => str_repeat('c', 64),
        'fingerprint' => str_repeat('d', 64),
        'segment_kind' => 'post_shift',
        'starts_at' => '2026-07-20 14:00:00',
        'ends_at' => '2026-07-20 14:30:00',
        'minutes' => 30,
        'rate_minutes' => ['ordinary' => 0, 'extra25' => 30, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
        'reason' => 'Revisión operativa',
    ];
    $approved = OvertimeDecision::factory()->create([...$snapshot, 'decision' => OvertimeDecision::APPROVED]);
    $rejected = OvertimeDecision::factory()->create([
        ...$snapshot,
        'decision' => OvertimeDecision::REJECTED,
        'supersedes_id' => $approved->id,
    ]);

    expect($approved->supersedingDecision->is($rejected))->toBeTrue()
        ->and($rejected->supersedes->is($approved))->toBeTrue()
        ->and(OvertimeDecision::current()->sole()->is($rejected))->toBeTrue();

    expect(fn () => OvertimeDecision::factory()->create([
        ...$snapshot,
        'decision' => OvertimeDecision::REJECTED,
        'supersedes_id' => $approved->id,
    ]))->toThrow(QueryException::class);
});

test('isolates overtime decisions by current company', function () {
    [$company, $period, $employee, $actor] = overtimeDecisionContext();
    [$otherCompany, $otherPeriod, $otherEmployee, $otherActor] = overtimeDecisionContext();

    OvertimeDecision::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
    ]);
    OvertimeDecision::factory()->create([
        'company_id' => $otherCompany->id,
        'pay_period_id' => $otherPeriod->id,
        'employee_id' => $otherEmployee->id,
        'decided_by' => $otherActor->id,
    ]);

    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(OvertimeDecision::query()->sole()->company_id)->toBe($company->id)
        ->and(OvertimeDecision::withoutCompanyScope()->count())->toBe(2);
});

function overtimeDecisionContext(): array
{
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-16',
        'end_date' => '2026-07-31',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();

    return [$company, $period, $employee, $actor];
}
