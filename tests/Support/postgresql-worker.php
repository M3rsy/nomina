<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\HolidayCalendar;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\RawMarkMutationGuard;
use App\Services\Payroll\PayrollProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DatabaseIsolationGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DatabaseIsolationGuard::assertPostgreSqlConnection($app);

$emit = static function (string $checkpoint, array $data = []): void {
    fwrite(STDOUT, json_encode(['checkpoint' => $checkpoint] + $data, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
};
$await = static function (string $release): void {
    $command = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);

    if (($command['release'] ?? null) !== $release) {
        throw new RuntimeException("Expected release command [{$release}].");
    }
};

$emit('ready', ['backend_pid' => (int) $identity->pid]);
$mode = $argv[1] ?? '';
$payload = json_decode($argv[2] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
$runPayroll = static function (bool $hold = false) use ($await, $emit, $payload): void {
    try {
        if ($hold) {
            DB::beginTransaction();
        }

        $period = PayPeriod::withoutCompanyScope()->findOrFail($payload['pay_period_id']);
        $report = app(PayrollProcessor::class)->processPayPeriod($period);
        $results = $report->resultsInserted + $report->resultsUpdated;

        if ($hold) {
            $emit('processed');
            $await('commit');
            DB::commit();
        }

        $emit('succeeded', ['results' => $results]);
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $emit('failed', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
    }
};

if ($mode === 'barrier') {
    $await('finish');
    $emit('finished');
} elseif ($mode === 'pay-period-hold') {
    $await('start');
    DB::beginTransaction();
    PayPeriod::withoutCompanyScope()->whereKey($payload['pay_period_id'])->lockForUpdate()->firstOrFail();
    $emit('locked');
    $await('commit');
    DB::commit();
    $emit('committed');
} elseif ($mode === 'overtime-decision') {
    $await('start');

    try {
        $decision = app(OvertimeDecisionRecorder::class)->decide(
            PayPeriod::withoutCompanyScope()->findOrFail($payload['pay_period_id']),
            Employee::withoutCompanyScope()->findOrFail($payload['employee_id']),
            $payload['work_date'],
            $payload['attendance_fact_key'],
            OvertimeDecision::APPROVED,
            'PostgreSQL canonical lock race',
            User::query()->findOrFail($payload['user_id']),
        );
        $emit('succeeded', ['fingerprint' => $decision->fingerprint]);
    } catch (Throwable $exception) {
        $emit('failed', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
    }
} elseif ($mode === 'holiday-activate-hold') {
    $await('start');
    DB::beginTransaction();

    try {
        $holiday = Holiday::withoutCompanyScope()->findOrFail($payload['holiday_id']);
        $company = Company::query()->findOrFail($holiday->company_id);
        $calendar = app(HolidayCalendar::class);
        $calendar->save($company, $holiday, [
            'date' => $holiday->date,
            'name' => $holiday->name,
            'description' => $holiday->description,
            'is_active' => true,
        ]);
        $generation = $calendar->capture($company, $holiday->date)->generation($holiday->date);
        $emit('mutated', ['generation' => $generation]);
        $await('commit');
        DB::commit();
        $emit('committed');
    } catch (Throwable $exception) {
        DB::rollBack();
        $emit('failed', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
    }
} elseif ($mode === 'raw-mark-hold') {
    $await('start');
    DB::beginTransaction();

    try {
        $mark = RawMark::withoutCompanyScope()->findOrFail($payload['raw_mark_id']);
        $employee = Employee::withoutCompanyScope()->findOrFail($mark->employee_id);
        app(RawMarkMutationGuard::class)->mutate(
            $mark,
            function (RawMark $lockedMark) use ($payload): void {
                $revisions = $lockedMark->metadata['revisions'] ?? [];
                $revisions[] = [
                    'action' => 'edit_event_at',
                    'user_id' => $payload['user_id'],
                    'reason' => 'PostgreSQL concurrency correction',
                    'old_event_at' => $lockedMark->event_at->toDateTimeString(),
                    'new_event_at' => $payload['event_at'],
                ];
                $lockedMark->update([
                    'event_at' => $payload['event_at'],
                    'status' => 'corrected',
                    'metadata' => ['revisions' => $revisions],
                ]);
            },
            targetEventAt: $payload['event_at'],
        );
        $generation = app(AttendanceFactGenerationTracker::class)->current($employee, '2026-01-05');
        $emit('mutated', ['generation' => $generation]);
        $await('commit');
        DB::commit();
        $emit('committed');
    } catch (Throwable $exception) {
        DB::rollBack();
        $emit('failed', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
    }
} elseif (in_array($mode, ['payroll', 'payroll-hold'], true)) {
    $await('start');
    $runPayroll($mode === 'payroll-hold');
} else {
    throw new RuntimeException("Unknown worker mode [{$mode}].");
}
