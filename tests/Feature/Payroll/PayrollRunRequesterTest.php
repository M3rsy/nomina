<?php

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunRequester;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(PermissionRoleSeeder::class));

function requestPayrollRun(PayPeriod $period, User $actor, ?string $key = null): PayrollRun
{
    return app(PayrollRunRequester::class)->request($period, $actor, $key ?? (string) Str::uuid());
}

test('queues a payroll run for a ready pay period', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $run = requestPayrollRun($period, $actor);
    expect($run->status)->toBe(PayrollRun::QUEUED)
        ->and($run->company_id)->toBe($company->id)
        ->and($run->pay_period_id)->toBe($period->id)
        ->and($run->requested_by)->toBe($actor->id)
        ->and($run->company?->is($company))->toBeTrue()
        ->and($run->payPeriod?->is($period))->toBeTrue()
        ->and($run->requester?->is($actor))->toBeTrue()
        ->and($run->started_at)->toBeNull()
        ->and($run->finished_at)->toBeNull()
        ->and($run->last_error)->toBeNull()
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(1);
});

test('returns the active run instead of creating a duplicate', function () {
    $company = Company::factory()->create();
    $requester = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $otherOperator = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);

    $first = requestPayrollRun($period, $requester);
    $queuedRetry = requestPayrollRun($period, $otherOperator);
    $first->markProcessing();
    $period->update(['status' => 'processing']);
    $processingRetry = requestPayrollRun($period, $otherOperator);

    expect($queuedRetry->is($first))->toBeTrue()
        ->and($processingRetry->is($first))->toBeTrue()
        ->and($processingRetry->requested_by)->toBe($requester->id)
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(1);
});

test('database prevents competing active reservations for one pay period', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    requestPayrollRun($period, $actor);

    expect(fn () => PayrollRun::withoutCompanyScope()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'requested_by' => $actor->id,
        'request_key' => (string) Str::uuid(),
        'status' => PayrollRun::QUEUED,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('locks the company context before its pay period', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $lockOrder = [];
    Company::retrieved(function () use (&$lockOrder) {
        $lockOrder[] = 'company';
    });
    PayPeriod::retrieved(function () use (&$lockOrder) {
        $lockOrder[] = 'pay_period';
    });

    requestPayrollRun($period, $actor);

    expect(array_slice($lockOrder, 0, 2))->toBe(['company', 'pay_period']);
});

test('request keys distinguish terminal replay from explicit retry', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $firstKey = (string) Str::uuid();
    $first = requestPayrollRun($period, $actor, $firstKey);
    $first->markFailed('Calculation failed');

    $replay = requestPayrollRun($period, $actor, $firstKey);
    $retry = requestPayrollRun($period, $actor);

    expect($replay->is($first))->toBeTrue()
        ->and($replay->status)->toBe(PayrollRun::FAILED)
        ->and($retry->isNot($first))->toBeTrue()
        ->and($retry->status)->toBe(PayrollRun::QUEUED)
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(2);
});

test('records processing and completion lifecycle timestamps', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $run = requestPayrollRun($period, $actor);

    $run->markProcessing();

    expect($run->status)->toBe(PayrollRun::PROCESSING)
        ->and($run->isActive())->toBeTrue()
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->toBeNull();

    $run->markCompleted();

    expect($run->status)->toBe(PayrollRun::COMPLETED)
        ->and($run->isActive())->toBeFalse()
        ->and($run->active_key)->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->last_error)->toBeNull();
});

test('terminal payroll runs cannot be resurrected or rewritten', function (string $status) {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $run = requestPayrollRun($period, $actor);
    if ($status === PayrollRun::COMPLETED) {
        $run->markProcessing();
        $run->markCompleted();
    } else {
        $run->markFailed('Calculation failed');
    }
    $finishedAt = $run->finished_at;

    expect(fn () => $run->markProcessing())->toThrow(LogicException::class)
        ->and(fn () => $run->markFailed('late failure'))->toThrow(LogicException::class)
        ->and($run->fresh()->status)->toBe($status)
        ->and($run->fresh()->finished_at->equalTo($finishedAt))->toBeTrue();
})->with([PayrollRun::COMPLETED, PayrollRun::FAILED]);

test('rejects a new run unless the pay period is ready', function (string $status) {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => $status]);

    expect(fn () => requestPayrollRun($period, $actor))
        ->toThrow(ValidationException::class)
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(0);
})->with(['draft', 'processing', 'processed', 'cancelled']);

test('rejects an operator from another company', function () {
    $periodCompany = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    $actor = User::factory()->forCompany($foreignCompany)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($periodCompany)->create(['status' => 'ready']);

    expect(fn () => requestPayrollRun($period, $actor))
        ->toThrow(AuthorizationException::class)
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(0);
});
