<?php

use App\Jobs\ProcessPayrollRun;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\PayrollRun;
use App\Models\PayrollRunTelemetry;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayrollProcessor;
use App\Services\Payroll\PayrollProcessReport;
use App\Services\Payroll\PayrollRunRequester;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    Queue::fake();
});

function queuedPayrollRun(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);

    return [$period, $actor];
}

function payrollRunWorkerFixture(): array
{
    [$period, $actor] = queuedPayrollRun();
    $company = $period->company;
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    foreach ($company->defaultWorkSchedules() as $day => $schedule) {
        WorkSchedule::factory()->forProfile($profile)->create($schedule + ['day_of_week' => $day]);
    }
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2020-01-01', 'Payroll run worker');
    $run = app(PayrollRunRequester::class)->request($period, $actor, (string) Str::uuid());

    return [$period, $run];
}

test('dispatches a new payroll run only after commit', function () {
    [$period, $actor] = queuedPayrollRun();

    DB::transaction(function () use ($period, $actor): void {
        app(PayrollRunRequester::class)->request($period, $actor, (string) Str::uuid());
        Queue::assertNothingPushed();
    });

    Queue::assertPushed(ProcessPayrollRun::class, 1);
});

test('redispatches an exact queued request to recover a lost queue push', function () {
    [$period, $actor] = queuedPayrollRun();
    $key = (string) Str::uuid();
    $first = app(PayrollRunRequester::class)->request($period, $actor, $key);
    Queue::fake();

    $replay = app(PayrollRunRequester::class)->request($period, $actor, $key);

    expect($replay->is($first))->toBeTrue();
    Queue::assertPushed(ProcessPayrollRun::class, 1);
});

test('processes a queued run through the payroll processor', function () {
    [$period, $run] = payrollRunWorkerFixture();

    (new ProcessPayrollRun($run->id))->handle(app(PayrollProcessor::class));
    $results = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->get();

    expect($period->fresh()->status)->toBe('processed')
        ->and($run->fresh()->status)->toBe('completed')
        ->and(PayrollRunTelemetry::query()->where('payroll_run_id', $run->id)->pluck('event')->all())
        ->toBe(['queued', 'started', 'completed'])
        ->and($results)->not->toBeEmpty()
        ->and($results->every(fn (PayrollResult $result): bool => is_array($result->day_snapshot)
            && is_string($result->snapshot_hash) && strlen($result->snapshot_hash) === 64))->toBeTrue();
});

test('runs payroll processing outside job state transactions', function () {
    [$period, $actor] = queuedPayrollRun();
    $run = app(PayrollRunRequester::class)->request($period, $actor, (string) Str::uuid());
    $harnessLevel = DB::transactionLevel();
    $processingLevel = null;
    $processor = Mockery::mock(PayrollProcessor::class);
    $processor->shouldReceive('processPayPeriod')->once()->andReturnUsing(
        function () use (&$processingLevel): PayrollProcessReport {
            $processingLevel = DB::transactionLevel();

            return new PayrollProcessReport;
        },
    );

    (new ProcessPayrollRun($run->id))->handle($processor);

    expect($processingLevel)->toBe($harnessLevel)
        ->and($run->fresh()->status)->toBe('completed');
});

test('reconciles committed payroll when completion persistence fails', function () {
    [$period, $run] = payrollRunWorkerFixture();
    $job = new ProcessPayrollRun($run->id);
    DB::statement("create trigger reject_payroll_completion before update of status on payroll_runs when new.status = 'completed' begin select raise(abort, 'completion failed'); end");
    $failure = null;

    try {
        $job->handle(app(PayrollProcessor::class));
    } catch (Throwable $exception) {
        $failure = $exception;
    } finally {
        DB::statement('drop trigger reject_payroll_completion');
    }

    $resultIds = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->pluck('id');
    expect($failure)->not->toBeNull()
        ->and($period->fresh()->status)->toBe('processed')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->count())->toBeGreaterThan(0)
        ->and($run->fresh()->status)->toBe('processing')
        ->and($run->fresh()->last_error)->toContain('completion failed');

    $processor = Mockery::mock(PayrollProcessor::class);
    $processor->shouldNotReceive('processPayPeriod');
    $job->handle($processor);

    expect($run->fresh()->status)->toBe('completed')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->pluck('id'))->toEqual($resultIds);
});

test('completed replay is a no-op', function () {
    [$period, $run] = payrollRunWorkerFixture();
    $job = new ProcessPayrollRun($run->id);
    $job->handle(app(PayrollProcessor::class));
    $finishedAt = $run->fresh()->finished_at;
    $resultCount = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->count();

    $job->handle(app(PayrollProcessor::class));
    $job->failed(new RuntimeException('late failure'));

    expect($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->finished_at->equalTo($finishedAt))->toBeTrue()
        ->and($run->fresh()->last_error)->toBeNull()
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->count())->toBe($resultCount);
});

test('failed replay is a no-op', function () {
    [$period, $actor] = queuedPayrollRun();
    $run = app(PayrollRunRequester::class)->request($period, $actor, (string) Str::uuid());
    $run->markFailed('terminal failure');
    $processor = Mockery::mock(PayrollProcessor::class);
    $processor->shouldNotReceive('processPayPeriod');

    (new ProcessPayrollRun($run->id))->handle($processor);

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->last_error)->toBe('terminal failure');
});

test('processing retry resumes calculation safely', function () {
    [$period, $run] = payrollRunWorkerFixture();
    $run->markProcessing();

    (new ProcessPayrollRun($run->id))->handle(app(PayrollProcessor::class));

    expect($period->fresh()->status)->toBe('processed')
        ->and($run->fresh()->status)->toBe('completed');
});

test('uses bounded worker failures and a payroll-run overlap lock', function () {
    $job = new ProcessPayrollRun(123);
    $middleware = $job->middleware()[0];

    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(5)
        ->and($job->backoff)->toBe(10)
        ->and($job->timeout)->toBe(240)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($middleware->releaseAfter)->toBe(300)
        ->and($middleware->expiresAfter)->toBe(300)
        ->and($middleware->getLockKey(new stdClass))->toContain('payroll-run:123');
});

test('retries infrastructure exceptions before recording terminal failure', function () {
    [, $run] = payrollRunWorkerFixture();
    $processor = Mockery::mock(PayrollProcessor::class);
    $processor->shouldReceive('processPayPeriod')->once()->andThrow(new RuntimeException('database unavailable'));
    $job = new ProcessPayrollRun($run->id);

    expect(fn () => $job->handle($processor))->toThrow(RuntimeException::class)
        ->and($run->fresh()->status)->toBe('processing')
        ->and($run->fresh()->last_error)->toBe('database unavailable');

    $job->failed(new RuntimeException('database unavailable'));

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->last_error)->toBe('database unavailable')
        ->and(PayrollRunTelemetry::query()->where('payroll_run_id', $run->id)->latest('id')->value('code'))
        ->toBe('processing_failed');
});

test('records a worker timeout as terminal failure', function () {
    [$period, $actor] = queuedPayrollRun();
    $run = app(PayrollRunRequester::class)->request($period, $actor, (string) Str::uuid());

    $job = new ProcessPayrollRun($run->id);
    $job->failed(new TimeoutExceededException('worker timed out'));
    $job->failed(new RuntimeException('late failure'));

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->last_error)->toBe('worker timed out');
});

test('terminalizes a queued run when its pay period was deleted', function () {
    [$period, $run] = payrollRunWorkerFixture();
    $period->delete();
    $processor = Mockery::mock(PayrollProcessor::class);
    $processor->shouldNotReceive('processPayPeriod');

    (new ProcessPayrollRun($run->id))->handle($processor);

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->active_key)->toBeNull()
        ->and($run->fresh()->last_error)->toContain('no longer available')
        ->and(PayPeriod::withTrashed()->findOrFail($period->id)->status)->toBe('ready')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->count())->toBe(0);
});

test('failed callback terminalizes a processing run whose period was deleted', function () {
    [$period, $run] = payrollRunWorkerFixture();
    $run->markProcessing();
    $period->delete();

    (new ProcessPayrollRun($run->id))->failed(new RuntimeException('worker crashed'));

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->active_key)->toBeNull()
        ->and($run->fresh()->last_error)->toBe('worker crashed')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->count())->toBe(0);
});

test('processes outside the active tenant while locking company period then run', function () {
    [$period, $run] = payrollRunWorkerFixture();
    $order = [];
    Company::retrieved(function () use (&$order) {
        $order[] = 'company';
    });
    PayPeriod::retrieved(function () use (&$order) {
        $order[] = 'pay_period';
    });
    PayrollRun::retrieved(function () use (&$order) {
        $order[] = 'payroll_run';
    });
    app(CurrentCompany::class)->set(Company::factory()->create());

    (new ProcessPayrollRun($run->id))->handle(app(PayrollProcessor::class));

    expect(array_slice($order, 0, 3))->toBe(['company', 'pay_period', 'payroll_run'])
        ->and($period->fresh()->status)->toBe('processed')
        ->and($run->fresh()->status)->toBe('completed');
});
