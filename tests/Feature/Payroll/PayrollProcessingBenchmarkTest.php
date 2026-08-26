<?php

use App\Jobs\ProcessPayrollRun;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollRunTelemetry;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\PayrollPeriodReviewSnapshot;
use App\Services\Payroll\PayrollProcessor;
use App\Services\Payroll\PayrollRunRequester;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Str;

dataset('payroll benchmark profiles', [
    '100 employees × 15 days' => [100, 15],
    '500 employees × 15 days' => [500, 15],
    '1000 employees × 15 days' => [1000, 15],
]);

test('benchmarks payroll processing and review with an opt-in profile', function (int $employeeCount, int $dayCount): void {
    $profileName = "{$employeeCount}x{$dayCount}";
    $reviewPrepassMode = getenv('PAYROLL_BENCHMARK_REVIEW_PREPASS_MODE') ?: 'treatment';

    if (getenv('PAYROLL_BENCHMARK') !== '1' || ($selected = getenv('PAYROLL_BENCHMARK_PROFILE')) !== false && $selected !== $profileName) {
        $this->markTestSkipped('Set PAYROLL_BENCHMARK=1 and optionally PAYROLL_BENCHMARK_PROFILE='.$profileName.'.');
    }

    if (! in_array($reviewPrepassMode, ['control', 'treatment'], true)) {
        throw new InvalidArgumentException('PAYROLL_BENCHMARK_REVIEW_PREPASS_MODE must be control or treatment.');
    }

    $this->seed(PermissionRoleSeeder::class);
    $start = CarbonImmutable::parse('2026-07-01');
    $end = $start->addDays($dayCount - 1);
    $company = Company::factory()->create();
    $scheduleProfile = WorkScheduleProfile::factory()->forCompany($company)->create();

    foreach (range(0, 6) as $dayOfWeek) {
        WorkSchedule::factory()->forProfile($scheduleProfile)->create([
            'day_of_week' => $dayOfWeek,
            'is_working_day' => true,
            'start_time' => '06:00',
            'end_time' => '14:00',
            'base_ordinary_hours' => 8,
        ]);
    }

    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => $start,
        'end_date' => $end,
        'status' => 'uploaded',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $employees = Employee::factory()->count($employeeCount)->forCompany($company)->create();
    $rowNumber = 1;

    foreach ($employees as $employee) {
        app(EmployeeScheduleAssigner::class)->assign($employee, $scheduleProfile, $start, 'Benchmark fixture');

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            foreach (['06:00:00', '14:00:00'] as $time) {
                RawMark::query()->create([
                    'company_id' => $company->id,
                    'pay_period_id' => $period->id,
                    'uploaded_file_id' => $file->id,
                    'employee_external_id' => $employee->external_id,
                    'employee_id' => $employee->id,
                    'event_at' => "{$date->toDateString()} {$time}",
                    'raw_line' => "{$employee->external_id} {$date->toDateString()} {$time}",
                    'source' => 'glg',
                    'row_number' => $rowNumber++,
                    'status' => 'valid',
                ]);
            }
        }
    }

    expect(RawMark::query()
        ->where('uploaded_file_id', $file->id)
        ->orderBy('row_number')
        ->pluck('row_number')
        ->all())
        ->toBe(range(1, $employeeCount * $dayCount * 2));

    $reviewCount = 0;
    $reviewSnapshotMetrics = [];

    if ($reviewPrepassMode === 'treatment') {
        memory_reset_peak_usage();
        $reviewSnapshotStartedAt = hrtime(true);
        $reviewCount = app(PayrollPeriodReviewSnapshot::class)->forPeriod($period)['reviews']->count();
        $reviewSnapshotMetrics = [
            'review_snapshot_duration_ms' => max(0, (int) round((hrtime(true) - $reviewSnapshotStartedAt) / 1_000_000)),
            'review_snapshot_peak_memory_mb' => max(0, (int) ceil(memory_get_peak_usage(true) / 1024 / 1024)),
        ];
    }
    $period->update(['status' => 'ready']);

    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $run = app(PayrollRunRequester::class)->request($period, $actor, (string) Str::uuid());

    fwrite(STDOUT, json_encode([
        'benchmark_marker' => 'before_process_payroll_run',
        'review_prepass_mode' => $reviewPrepassMode,
        'php_memory_used_mb' => max(0, (int) ceil(memory_get_usage(true) / 1024 / 1024)),
    ], JSON_THROW_ON_ERROR).PHP_EOL);

    (new ProcessPayrollRun($run->id))->handle(app(PayrollProcessor::class));
    $telemetry = PayrollRunTelemetry::query()
        ->where('payroll_run_id', $run->id)
        ->where('event', PayrollRunTelemetry::COMPLETED)
        ->sole();

    expect($telemetry->employee_count)->toBe($employeeCount)
        ->and($telemetry->day_count)->toBe($dayCount)
        ->and($telemetry->result_count)->toBe($telemetry->inserted_count + $telemetry->reused_count)
        ->and($telemetry->duration_ms)->toBeInt()
        ->and($telemetry->query_count)->toBeGreaterThan(0);

    if ($reviewPrepassMode === 'treatment') {
        expect($reviewCount)->toBeGreaterThan(0);
    }

    $metrics = [
        'profile' => $profileName,
        'review_prepass_mode' => $reviewPrepassMode,
        'duration_ms' => $telemetry->duration_ms,
        'queue_wait_ms' => $telemetry->queue_wait_ms,
        'db_time_ms' => $telemetry->db_time_ms,
        'query_count' => $telemetry->query_count,
        'peak_memory_mb' => $telemetry->peak_memory_mb,
        'employee_count' => $telemetry->employee_count,
        'day_count' => $telemetry->day_count,
        'result_count' => $telemetry->result_count,
        'inserted_count' => $telemetry->inserted_count,
        'reused_count' => $telemetry->reused_count,
        ...$reviewSnapshotMetrics,
    ];

    if ($telemetry->queue_wait_ms === null) {
        unset($metrics['queue_wait_ms']);
    }

    $reportedMetrics = json_decode(json_encode($metrics, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    if ($reviewPrepassMode === 'treatment') {
        expect($reportedMetrics)->toHaveKeys([
            'review_snapshot_duration_ms',
            'review_snapshot_peak_memory_mb',
        ])
            ->and($reportedMetrics['review_snapshot_duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($reportedMetrics['review_snapshot_peak_memory_mb'])->toBeInt()->toBeGreaterThanOrEqual(0);
    }

    fwrite(STDOUT, json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL);
})->with('payroll benchmark profiles');
