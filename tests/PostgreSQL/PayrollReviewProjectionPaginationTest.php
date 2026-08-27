<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollReviewEntry;
use App\Services\Payroll\PayrollReviewProjection;
use Illuminate\Support\Facades\DB;

test('PostgreSQL paginates projected overtime before loading employees', function (): void {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $generation = 'postgresql-projected-overtime-pagination';

    foreach (range(1, 26) as $number) {
        $employee = Employee::factory()->forCompany($company)->create([
            'external_id' => sprintf('PG-%03d', $number),
        ]);
        PayrollReviewEntry::withoutCompanyScope()->create(postgresProjectedOvertimeEntry(
            companyId: $company->id,
            payPeriodId: $period->id,
            employeeId: $employee->id,
            generation: $generation,
            sourceKey: sprintf('postgres-candidate-%03d', $number),
        ));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $data = app(PayrollReviewProjection::class)->overtimeRows($period, $generation, [
            'search' => '', 'status' => 'pending', 'date' => '', 'rate' => 'extra50',
        ], 2);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $pageQuery = collect($queries)->first(fn (array $query): bool => str_contains(
        strtolower($query['query']),
        'from "payroll_review_entries"'
    ) && str_contains(strtolower($query['query']), 'limit 25 offset 25'));

    expect($data['rows']->total())->toBe(26)
        ->and($data['rows']->count())->toBe(1)
        ->and($data['groups']->sole()['employee']->external_id)->toBe('PG-026')
        ->and($pageQuery)->not->toBeNull()
        ->and(strtolower($pageQuery['query']))->toContain('"rate_extra50_minutes" > ?');
});

function postgresProjectedOvertimeEntry(
    int $companyId,
    int $payPeriodId,
    int $employeeId,
    string $generation,
    string $sourceKey,
): array {
    return [
        'company_id' => $companyId,
        'pay_period_id' => $payPeriodId,
        'employee_id' => $employeeId,
        'work_date' => '2026-07-20',
        'type' => 'overtime_candidate',
        'status' => 'pending',
        'source_key' => $sourceKey,
        'source_fingerprint' => hash('sha256', $sourceKey),
        'generation' => $generation,
        'occurred_at' => '2026-07-20 14:00:00',
        'rate_ordinary_minutes' => 0,
        'rate_extra25_minutes' => 0,
        'rate_extra50_minutes' => 30,
        'rate_extra75_minutes' => 0,
        'rate_extra100_minutes' => 0,
        'payload' => [
            'segment' => [
                'kind' => 'post_shift', 'key' => $sourceKey, 'fingerprint' => hash('sha256', $sourceKey),
                'start' => '2026-07-20 14:00:00', 'end' => '2026-07-20 14:30:00', 'minutes' => 30,
                'rate_minutes' => ['ordinary' => 0, 'extra25' => 0, 'extra50' => 30, 'extra75' => 0, 'extra100' => 0],
            ],
            'analysis' => [
                'work_date' => '2026-07-20', 'entry_at' => '2026-07-20 06:00:00', 'exit_at' => '2026-07-20 14:30:00',
                'payroll_policy_key' => 'schedule-overlap-v1', 'excluded_transfer_minutes' => 0,
            ],
            'occurrence' => ['scheduled_start' => '2026-07-20 06:00:00', 'scheduled_end' => '2026-07-20 14:00:00'],
            'resolution' => null,
        ],
    ];
}
