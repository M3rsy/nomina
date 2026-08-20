<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PAYROLL_DAY_SNAPSHOT_MIGRATION = 'database/migrations/2026_07_30_000005_add_payroll_day_snapshot_to_payroll_results.php';
const PAYROLL_DAY_SNAPSHOT_MIGRATION_NAME = '2026_07_30_000005_add_payroll_day_snapshot_to_payroll_results';

function rollbackPayrollDaySnapshotMigration(): void
{
    Artisan::call('migrate:rollback', [
        '--path' => PAYROLL_DAY_SNAPSHOT_MIGRATION,
        '--force' => true,
    ]);
}

/** @return array<string, mixed> */
function payrollGenerationSchemaState(): array
{
    $catalogRows = fn (string $sql): array => collect(DB::select($sql))
        ->map(fn (object $row): array => (array) $row)
        ->all();

    return [
        'columns' => $catalogRows(<<<'SQL'
            SELECT table_name, column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name IN ('pay_periods', 'payroll_results')
            ORDER BY table_name, ordinal_position
            SQL),
        'constraints' => $catalogRows(<<<'SQL'
            SELECT c.conname, c.contype, pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE n.nspname = current_schema()
              AND t.relname IN ('pay_periods', 'payroll_results')
            ORDER BY c.conname
            SQL),
        'triggers' => $catalogRows(<<<'SQL'
            SELECT t.tgname, pg_get_triggerdef(t.oid) AS definition
            FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema()
              AND c.relname = 'payroll_results'
              AND NOT t.tgisinternal
            ORDER BY t.tgname
            SQL),
    ];
}

function createPayrollRollbackState(string $state): void
{
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(match ($state) {
        'current generation' => ['current_result_generation' => 2],
        'authorized generation' => ['authorized_result_generation' => 2],
        default => [],
    });

    if (in_array($state, ['current generation', 'authorized generation'], true)) {
        return;
    }

    $employee = Employee::factory()->forCompany($company)->create();
    $attributes = match ($state) {
        'result generation' => ['result_generation' => 2],
        'day snapshot' => ['day_snapshot' => ['generation' => 1]],
        'snapshot hash' => ['snapshot_hash' => str_repeat('1', 64)],
        default => [],
    };
    $first = PayrollResult::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)
        ->create(['date' => '2026-08-01', ...$attributes]);

    if ($state === 'superseded result') {
        PayrollResult::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create([
            'date' => '2026-08-02',
            'supersedes_id' => $first->id,
        ]);
    }
}

/** @return array<string, mixed> */
function payrollGenerationDataState(): array
{
    return [
        'periods' => DB::table('pay_periods')
            ->select(['id', 'current_result_generation', 'authorized_result_generation'])
            ->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'results' => DB::table('payroll_results')
            ->select(['id', 'pay_period_id', 'result_generation', 'supersedes_id', 'day_snapshot', 'snapshot_hash'])
            ->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'migration' => (array) DB::table('migrations')
            ->where('migration', PAYROLL_DAY_SNAPSHOT_MIGRATION_NAME)->first(),
    ];
}

test('refuses to roll back each immutable payroll state before changing schema or data', function (string $state) {
    createPayrollRollbackState($state);
    $schemaBefore = payrollGenerationSchemaState();
    $dataBefore = payrollGenerationDataState();

    expect(fn () => rollbackPayrollDaySnapshotMigration())
        ->toThrow(
            RuntimeException::class,
            'Cannot roll back payroll day snapshots: immutable payroll generation or snapshot history exists. Keep this migration applied and use a forward-only correction.',
        )
        ->and(payrollGenerationSchemaState())->toBe($schemaBefore)
        ->and(payrollGenerationDataState())->toBe($dataBefore);
})->with([
    'period current generation' => 'current generation',
    'period authorized generation' => 'authorized generation',
    'result generation' => 'result generation',
    'superseded result' => 'superseded result',
    'day snapshot' => 'day snapshot',
    'snapshot hash' => 'snapshot hash',
]);

test('rolls back and reapplies the snapshot migration for a legacy generation one row', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    PayrollResult::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create([
        'date' => '2026-08-01',
    ]);
    $legacyResult = (array) DB::table('payroll_results')
        ->select(['company_id', 'pay_period_id', 'employee_id', 'date'])
        ->first();

    rollbackPayrollDaySnapshotMigration();

    try {
        expect(Schema::hasColumn('pay_periods', 'current_result_generation'))->toBeFalse()
            ->and(Schema::hasColumn('pay_periods', 'authorized_result_generation'))->toBeFalse()
            ->and(Schema::hasColumn('payroll_results', 'result_generation'))->toBeFalse()
            ->and(Schema::hasColumn('payroll_results', 'supersedes_id'))->toBeFalse()
            ->and(Schema::hasColumn('payroll_results', 'day_snapshot'))->toBeFalse()
            ->and(Schema::hasColumn('payroll_results', 'snapshot_hash'))->toBeFalse()
            ->and(DB::table('migrations')->where('migration', PAYROLL_DAY_SNAPSHOT_MIGRATION_NAME)->exists())
            ->toBeFalse()
            ->and((array) DB::table('payroll_results')
                ->select(['company_id', 'pay_period_id', 'employee_id', 'date'])
                ->first())->toBe($legacyResult);
    } finally {
        Artisan::call('migrate', [
            '--path' => PAYROLL_DAY_SNAPSHOT_MIGRATION,
            '--force' => true,
        ]);
    }
});
