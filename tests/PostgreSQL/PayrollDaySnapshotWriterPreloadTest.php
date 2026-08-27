<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Services\Payroll\PayrollDaySnapshot;
use App\Services\Payroll\PayrollDaySnapshotWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @return array<string, mixed> */
function postgreSqlSnapshotWriterAttributes(Company $company, PayPeriod $period, Employee $employee): array
{
    $attributes = PayrollResult::factory()
        ->forCompany($company)
        ->forPayPeriod($period)
        ->forEmployee($employee)
        ->make([
            'date' => '2026-07-01',
            'result_generation' => 1,
        ])
        ->getAttributes();
    $attributes['date'] = '2026-07-01';

    return $attributes;
}

function postgreSqlState(Closure $operation): ?string
{
    try {
        $operation();
    } catch (QueryException $exception) {
        return $exception->getCode();
    }

    return null;
}

test('PostgreSQL rejects direct conflicting inserts and mutations after a snapshot writer preload', function (): void {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $writer = app(PayrollDaySnapshotWriter::class);
    $attributes = postgreSqlSnapshotWriterAttributes($company, $period, $employee);
    $snapshot = new PayrollDaySnapshot(['source' => 'postgresql-preload']);

    $writer->preloadForPayrollPeriod($company->id, $period->id, [$employee->id], '2026-07-01', '2026-07-01', 1);
    $stored = $writer->write($attributes, $snapshot);

    expect(postgreSqlState(fn () => DB::table('payroll_results')->insert([
        ...$attributes,
        'day_snapshot' => json_encode($snapshot->data, JSON_THROW_ON_ERROR),
        'snapshot_hash' => $snapshot->hash,
    ])))->toBe('23505')
        ->and(postgreSqlState(fn () => DB::table('payroll_results')->where('id', $stored->id)->update(['date' => '2026-07-02'])))
        ->toBe('23514')
        ->and(PayrollResult::withoutCompanyScope()->withoutGlobalScope('currentGeneration')->findOrFail($stored->id)->snapshot_hash)
        ->toBe($snapshot->hash);
});
