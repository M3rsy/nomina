<?php

use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\PostgreSqlWorker;

function postgresPayrollFixture(): array
{
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'ready',
    ]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'base_ordinary_hours' => 8,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2020-01-01', 'PostgreSQL race fixture');

    return [$company, $period, $employee];
}

function waitForPostgresBlocker(int $blockedPid, int $blockerPid): void
{
    $deadline = microtime(true) + 5;

    do {
        $blocking = DB::selectOne(
            'select count(*) as total from unnest(pg_blocking_pids(?)) as pid where pid = ?',
            [$blockedPid, $blockerPid],
        );

        if ((int) $blocking->total === 1) {
            return;
        }

        usleep(10_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Backend {$blockedPid} was not blocked by {$blockerPid}.");
}

test('rebuilds the isolated database and exposes committed fixtures', function () {
    $identity = DB::selectOne(
        'select current_database() as database, current_user as username, pg_backend_pid() as pid'
    );
    $company = Company::factory()->create(['name' => 'Committed fixture']);
    config(['database.connections.pgsql_testing_observer' => config('database.connections.pgsql_testing')]);
    $observer = DB::connection('pgsql_testing_observer');

    expect($identity->database)->toBe('nomina_test')
        ->and($identity->username)->toBe('nomina_test')
        ->and((int) $observer->selectOne('select pg_backend_pid() as pid')->pid)->not->toBe((int) $identity->pid)
        ->and($observer->table('companies')->where('id', $company->id)->value('name'))->toBe('Committed fixture');
});

test('coordinates two independent workers at deterministic barriers', function () {
    $first = PostgreSqlWorker::start('barrier');
    $second = PostgreSqlWorker::start('barrier');

    try {
        $firstReady = $first->waitFor('ready');
        $secondReady = $second->waitFor('ready');
        $liveWorkers = DB::selectOne(
            'select count(*) as total from pg_stat_activity where pid in (?, ?)',
            [$firstReady['backend_pid'], $secondReady['backend_pid']],
        );

        expect($firstReady['backend_pid'])->not->toBe($secondReady['backend_pid'])
            ->and((int) $liveWorkers->total)->toBe(2);

        $first->release('finish');
        $second->release('finish');
        $first->waitFor('finished');
        $second->waitFor('finished');
    } finally {
        $first->stop();
        $second->stop();
    }
});

test('serializes simultaneous payroll processing', function () {
    [, $period] = postgresPayrollFixture();
    $winner = PostgreSqlWorker::start('payroll-hold', ['pay_period_id' => $period->id]);
    $loser = PostgreSqlWorker::start('payroll', ['pay_period_id' => $period->id]);

    try {
        $winnerReady = $winner->waitFor('ready');
        $loserReady = $loser->waitFor('ready');
        $winner->release('start');
        $winner->waitFor('processed');
        $loser->release('start');
        waitForPostgresBlocker($loserReady['backend_pid'], $winnerReady['backend_pid']);
        $winner->release('commit');
        $failure = $loser->waitFor('failed');

        expect($winner->waitFor('succeeded')['results'])->toBe(1)
            ->and($failure['exception'])->toBe(InvalidArgumentException::class)
            ->and($failure['message'])->toBe('PayPeriod must be in ready state to process.')
            ->and($period->fresh()->status)->toBe('processed')
            ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->count())->toBe(1);
    } finally {
        $winner->stop();
        $loser->stop();
    }
});

test('payroll waits for a committed audited manual mark correction', function () {
    [$company, $period, $employee] = postgresPayrollFixture();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $actor = User::factory()->forCompany($company)->create();
    RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
        ->forEmployee($employee)->create(['event_at' => '2026-01-05 06:00:00', 'status' => 'valid']);
    $manual = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create([
        'uploaded_file_id' => null,
        'event_at' => '2026-01-05 14:00:00',
        'source' => RawMark::SOURCE_MANUAL,
        'status' => 'corrected',
        'metadata' => ['revisions' => [['action' => 'manual_create', 'user_id' => $actor->id]]],
    ]);
    app(AttendanceFactGenerationTracker::class)->advance($employee, '2026-01-05');
    $mutation = PostgreSqlWorker::start('raw-mark-hold', [
        'raw_mark_id' => $manual->id,
        'event_at' => '2026-01-05 13:30:00',
        'user_id' => $actor->id,
    ]);
    $payroll = PostgreSqlWorker::start('payroll', ['pay_period_id' => $period->id]);

    try {
        $mutationReady = $mutation->waitFor('ready');
        $payrollReady = $payroll->waitFor('ready');
        $mutation->release('start');
        expect($mutation->waitFor('mutated')['generation'])->toBe(2);
        $payroll->release('start');
        waitForPostgresBlocker($payrollReady['backend_pid'], $mutationReady['backend_pid']);
        $mutation->release('commit');
        $mutation->waitFor('committed');

        expect($payroll->waitFor('succeeded')['results'])->toBe(1)
            ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->sole()->exit_at->toDateTimeString())
            ->toBe('2026-01-05 13:30:00')
            ->and($manual->fresh()->metadata['revisions'])->toHaveCount(2)
            ->and(app(AttendanceFactGenerationTracker::class)->current($employee, '2026-01-05'))->toBe(2);
    } finally {
        $mutation->stop();
        $payroll->stop();
    }
});

test('audited mark correction serializes behind payroll without an employee-period deadlock', function () {
    [$company, $period, $employee] = postgresPayrollFixture();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $actor = User::factory()->forCompany($company)->create();
    RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
        ->forEmployee($employee)->create(['event_at' => '2026-01-05 06:00:00', 'status' => 'valid']);
    $manual = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create([
        'uploaded_file_id' => null,
        'event_at' => '2026-01-05 14:00:00',
        'source' => RawMark::SOURCE_MANUAL,
        'status' => 'corrected',
        'metadata' => ['revisions' => [['action' => 'manual_create', 'user_id' => $actor->id]]],
    ]);
    app(AttendanceFactGenerationTracker::class)->advance($employee, '2026-01-05');
    $periodHolder = PostgreSqlWorker::start('pay-period-hold', ['pay_period_id' => $period->id]);
    $payroll = PostgreSqlWorker::start('payroll', ['pay_period_id' => $period->id]);
    $correction = PostgreSqlWorker::start('raw-mark-hold', [
        'raw_mark_id' => $manual->id,
        'event_at' => '2026-01-05 13:30:00',
        'user_id' => $actor->id,
    ]);

    try {
        $holderReady = $periodHolder->waitFor('ready');
        $payrollReady = $payroll->waitFor('ready');
        $correctionReady = $correction->waitFor('ready');
        $periodHolder->release('start');
        $periodHolder->waitFor('locked');
        $payroll->release('start');
        waitForPostgresBlocker($payrollReady['backend_pid'], $holderReady['backend_pid']);
        $correction->release('start');
        waitForPostgresBlocker($correctionReady['backend_pid'], $payrollReady['backend_pid']);

        $periodHolder->release('commit');
        $periodHolder->waitFor('committed');
        expect($payroll->waitFor('succeeded')['results'])->toBe(1);
        $failure = $correction->waitFor('failed');

        expect($failure['message'])->not->toContain('40P01')
            ->and($failure['exception'])->toBe(ValidationException::class)
            ->and($manual->fresh()->event_at->toDateTimeString())->toBe('2026-01-05 14:00:00')
            ->and(app(AttendanceFactGenerationTracker::class)->current($employee, '2026-01-05'))->toBe(1);
    } finally {
        $periodHolder->stop();
        $payroll->stop();
        $correction->stop();
    }
});

test('audited manual mark serializes before payroll without a company-period deadlock', function () {
    app(PermissionRoleSeeder::class)->run();
    [$company, $period, $employee] = postgresPayrollFixture();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
        ->forEmployee($employee)->create(['event_at' => '2026-01-05 06:00:00', 'status' => 'valid']);
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $periodHolder = PostgreSqlWorker::start('pay-period-hold', ['pay_period_id' => $period->id]);
    $manualMark = PostgreSqlWorker::start('manual-mark', [
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-01-05',
        'event_at' => '2026-01-05 14:00:00',
        'user_id' => $actor->id,
    ]);
    $payroll = PostgreSqlWorker::start('payroll', ['pay_period_id' => $period->id]);

    try {
        $holderReady = $periodHolder->waitFor('ready');
        $manualReady = $manualMark->waitFor('ready');
        $payrollReady = $payroll->waitFor('ready');
        $periodHolder->release('start');
        $periodHolder->waitFor('locked');
        $manualMark->release('start');
        waitForPostgresBlocker($manualReady['backend_pid'], $holderReady['backend_pid']);
        $payroll->release('start');
        waitForPostgresBlocker($payrollReady['backend_pid'], $manualReady['backend_pid']);

        $periodHolder->release('commit');
        $periodHolder->waitFor('committed');
        expect($manualMark->waitFor('succeeded')['generation'])->toBe(1)
            ->and($payroll->waitFor('succeeded')['results'])->toBe(1)
            ->and(RawMark::withoutCompanyScope()->where('employee_id', $employee->id)->count())->toBe(2);
    } finally {
        $periodHolder->stop();
        $manualMark->stop();
        $payroll->stop();
    }
});

test('payroll waits for a committed holiday mutation and uses its captured context', function () {
    [$company, $period] = postgresPayrollFixture();
    $holiday = Holiday::factory()->forCompany($company)->inactive()->create(['date' => '2026-01-05']);
    $mutation = PostgreSqlWorker::start('holiday-activate-hold', ['holiday_id' => $holiday->id]);
    $payroll = PostgreSqlWorker::start('payroll', ['pay_period_id' => $period->id]);

    try {
        $mutationReady = $mutation->waitFor('ready');
        $payrollReady = $payroll->waitFor('ready');
        $mutation->release('start');
        expect($mutation->waitFor('mutated')['generation'])->toBe(1);
        $payroll->release('start');
        waitForPostgresBlocker($payrollReady['backend_pid'], $mutationReady['backend_pid']);
        $mutation->release('commit');
        $mutation->waitFor('committed');

        expect($payroll->waitFor('succeeded')['results'])->toBe(0)
            ->and($holiday->fresh()->is_active)->toBeTrue()
            ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->count())->toBe(0);
    } finally {
        $mutation->stop();
        $payroll->stop();
    }
});

test('holiday mutation waits while an audited attendance decision owns its payroll context', function (
    string $workerMode,
    string $entryAt,
    string $exitAt,
    string $analysisCollection,
    string $decisionModel,
) {
    app(PermissionRoleSeeder::class)->run();
    [$company, $period, $employee] = postgresPayrollFixture();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    collect([$entryAt, $exitAt])->each(
        fn (string $eventAt) => RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]),
    );
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $attendanceFact = app(PayrollShiftEvaluationResolver::class)
        ->review($period, $employee, '2026-01-05')
        ->analysis
        ->{$analysisCollection}
        ->sole();
    $holiday = Holiday::factory()->forCompany($company)->inactive()->create(['date' => '2026-01-05']);
    $periodHolder = PostgreSqlWorker::start('pay-period-hold', ['pay_period_id' => $period->id]);
    $authorization = PostgreSqlWorker::start($workerMode, [
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-01-05',
        'attendance_fact_key' => $attendanceFact->key,
        'user_id' => $actor->id,
    ]);
    $mutation = PostgreSqlWorker::start('holiday-activate-hold', ['holiday_id' => $holiday->id]);

    try {
        $holderReady = $periodHolder->waitFor('ready');
        $authorizationReady = $authorization->waitFor('ready');
        $mutationReady = $mutation->waitFor('ready');
        $periodHolder->release('start');
        $periodHolder->waitFor('locked');
        $authorization->release('start');
        waitForPostgresBlocker($authorizationReady['backend_pid'], $holderReady['backend_pid']);
        $mutation->release('start');
        waitForPostgresBlocker($mutationReady['backend_pid'], $authorizationReady['backend_pid']);

        $periodHolder->release('commit');
        $periodHolder->waitFor('committed');
        expect($authorization->waitFor('succeeded')['fingerprint'])->toBe($attendanceFact->fingerprint);
        expect($mutation->waitFor('mutated')['generation'])->toBe(1);
        $mutation->release('commit');
        $mutation->waitFor('committed');

        expect($decisionModel::withoutCompanyScope()->where('pay_period_id', $period->id)->count())->toBe(1)
            ->and($holiday->fresh()->is_active)->toBeTrue();
    } finally {
        $periodHolder->stop();
        $authorization->stop();
        $mutation->stop();
    }
})->with([
    'overtime authorization' => [
        'overtime-decision', '2026-01-05 06:00:00', '2026-01-05 14:30:00',
        'overtimeCandidates', OvertimeDecision::class,
    ],
    'attendance exception' => [
        'attendance-exception', '2026-01-05 06:15:00', '2026-01-05 14:00:00',
        'deficits', AttendanceException::class,
    ],
]);
