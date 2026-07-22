<?php

use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\CurrentCompany;
use Illuminate\Database\QueryException;

test('stores an immutable exact deficit exception snapshot', function () {
    [$company, $period, $employee, $actor] = attendanceExceptionContext();

    $exception = AttendanceException::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-20',
        'deficit_key' => str_repeat('a', 64),
        'fingerprint' => str_repeat('b', 64),
        'segment_kind' => 'late_arrival',
        'starts_at' => '2026-07-20 06:00:00',
        'ends_at' => '2026-07-20 06:15:00',
        'minutes' => 15,
        'rate_minutes' => ['ordinary' => 15, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
        'decision' => AttendanceException::GRANTED,
        'reason' => 'Demora autorizada por supervisión',
    ]);

    expect($exception->work_date->toDateString())->toBe('2026-07-20')
        ->and($exception->minutes)->toBe(15)
        ->and($exception->rate_minutes['ordinary'])->toBe(15)
        ->and($exception->company->is($company))->toBeTrue()
        ->and($exception->payPeriod->is($period))->toBeTrue()
        ->and($exception->employee->is($employee))->toBeTrue()
        ->and($exception->decider->is($actor))->toBeTrue();

    expect(fn () => $exception->update(['decision' => AttendanceException::REVOKED]))
        ->toThrow(LogicException::class)
        ->and(fn () => $exception->delete())
        ->toThrow(LogicException::class)
        ->and($exception->fresh()->decision)->toBe(AttendanceException::GRANTED);
});

test('keeps a linear append-only exception history', function () {
    [$company, $period, $employee, $actor] = attendanceExceptionContext();
    $snapshot = [
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
        'work_date' => '2026-07-20',
        'deficit_key' => str_repeat('c', 64),
        'fingerprint' => str_repeat('d', 64),
        'segment_kind' => 'early_departure',
        'starts_at' => '2026-07-20 13:45:00',
        'ends_at' => '2026-07-20 14:00:00',
        'minutes' => 15,
        'rate_minutes' => ['ordinary' => 15, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
        'reason' => 'Salida autorizada',
    ];
    $granted = AttendanceException::factory()->create([...$snapshot, 'decision' => AttendanceException::GRANTED]);
    $revoked = AttendanceException::factory()->create([
        ...$snapshot,
        'decision' => AttendanceException::REVOKED,
        'supersedes_id' => $granted->id,
    ]);

    expect($granted->supersedingException->is($revoked))->toBeTrue()
        ->and($revoked->supersedes->is($granted))->toBeTrue()
        ->and(AttendanceException::current()->sole()->is($revoked))->toBeTrue();

    expect(fn () => AttendanceException::factory()->create([
        ...$snapshot,
        'decision' => AttendanceException::REVOKED,
        'supersedes_id' => $granted->id,
    ]))->toThrow(QueryException::class);
});

test('isolates attendance exceptions by current company', function () {
    [$company, $period, $employee, $actor] = attendanceExceptionContext();
    [$otherCompany, $otherPeriod, $otherEmployee, $otherActor] = attendanceExceptionContext();

    AttendanceException::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'decided_by' => $actor->id,
    ]);
    AttendanceException::factory()->create([
        'company_id' => $otherCompany->id,
        'pay_period_id' => $otherPeriod->id,
        'employee_id' => $otherEmployee->id,
        'decided_by' => $otherActor->id,
    ]);

    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(AttendanceException::query()->sole()->company_id)->toBe($company->id)
        ->and(AttendanceException::withoutCompanyScope()->count())->toBe(2);
});

function attendanceExceptionContext(): array
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
