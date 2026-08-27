<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Services\Payroll\PayrollDaySnapshot;
use App\Services\Payroll\PayrollDaySnapshotWriter;

function snapshotWriterAttributes(Company $company, PayPeriod $period, Employee $employee, int $generation): array
{
    $attributes = PayrollResult::factory()
        ->forCompany($company)
        ->forPayPeriod($period)
        ->forEmployee($employee)
        ->make([
            'date' => '2026-07-01',
            'result_generation' => $generation,
        ])
        ->getAttributes();

    $attributes['date'] = '2026-07-01';

    return $attributes;
}

test('reuses an identical immutable snapshot at the same generation', function (): void {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $snapshot = new PayrollDaySnapshot(['source' => 'same-hash']);
    $writer = app(PayrollDaySnapshotWriter::class);

    $created = $writer->write(snapshotWriterAttributes($company, $period, $employee, 1), $snapshot);
    $reused = $writer->write(snapshotWriterAttributes($company, $period, $employee, 1), $snapshot);

    expect($reused->id)->toBe($created->id)
        ->and(PayrollResult::withoutCompanyScope()->count())->toBe(1);
});

test('rejects a different immutable snapshot at the same generation', function (): void {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $writer = app(PayrollDaySnapshotWriter::class);
    $attributes = snapshotWriterAttributes($company, $period, $employee, 1);

    $writer->write($attributes, new PayrollDaySnapshot(['source' => 'first']));

    expect(fn () => $writer->write($attributes, new PayrollDaySnapshot(['source' => 'conflict'])))
        ->toThrow(LogicException::class, 'Conflicting immutable payroll result already exists.');
});

test('links a later result generation to its immutable predecessor', function (): void {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $writer = app(PayrollDaySnapshotWriter::class);

    $predecessor = $writer->write(
        snapshotWriterAttributes($company, $period, $employee, 1),
        new PayrollDaySnapshot(['generation' => 1]),
    );
    $writer->preloadForPayrollPeriod(
        $company->id,
        $period->id,
        [$employee->id],
        '2026-07-01',
        '2026-07-01',
        2,
    );
    $successor = $writer->write(
        snapshotWriterAttributes($company, $period, $employee, 2),
        new PayrollDaySnapshot(['generation' => 2]),
    );

    expect($successor->supersedes_id)->toBe($predecessor->id)
        ->and(PayrollResult::withoutCompanyScope()->withoutGlobalScope('currentGeneration')->count())->toBe(2);
});

test('keeps snapshot writes isolated by company', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $periodA = PayPeriod::factory()->forCompany($companyA)->create();
    $periodB = PayPeriod::factory()->forCompany($companyB)->create();
    $employeeA = Employee::factory()->forCompany($companyA)->create();
    $employeeB = Employee::factory()->forCompany($companyB)->create();
    $writer = app(PayrollDaySnapshotWriter::class);
    $snapshot = new PayrollDaySnapshot(['source' => 'isolated']);

    $resultA = $writer->write(snapshotWriterAttributes($companyA, $periodA, $employeeA, 1), $snapshot);
    $resultB = $writer->write(snapshotWriterAttributes($companyB, $periodB, $employeeB, 1), $snapshot);

    expect($resultA->company_id)->toBe($companyA->id)
        ->and($resultB->company_id)->toBe($companyB->id)
        ->and(PayrollResult::withoutCompanyScope()->count())->toBe(2);
});

test('replaces cached results when the same writer preloads a different period and generation', function (): void {
    $company = Company::factory()->create();
    $firstPeriod = PayPeriod::factory()->forCompany($company)->create();
    $secondPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $writer = app(PayrollDaySnapshotWriter::class);
    $firstSnapshot = new PayrollDaySnapshot(['period' => 'first']);
    $preloadedResults = new ReflectionProperty($writer, 'preloadedResults');

    $first = $writer->write(snapshotWriterAttributes($company, $firstPeriod, $employee, 1), $firstSnapshot);
    $writer->preloadForPayrollPeriod($company->id, $firstPeriod->id, [$employee->id], '2026-07-01', '2026-07-01', 1);
    expect($preloadedResults->getValue($writer))->toHaveCount(1);

    $writer->preloadForPayrollPeriod($company->id, $secondPeriod->id, [$employee->id], '2026-07-01', '2026-07-01', 2);

    expect($preloadedResults->getValue($writer))->toBe([])
        ->and($writer->write(snapshotWriterAttributes($company, $firstPeriod, $employee, 1), $firstSnapshot)->id)->toBe($first->id);
});

test('falls back to the database outside the preloaded scope while preserving immutable reuse and conflict behavior', function (): void {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $writer = app(PayrollDaySnapshotWriter::class);
    $attributes = snapshotWriterAttributes($company, $period, $employee, 1);
    $attributes['date'] = '2026-07-02';
    $snapshot = new PayrollDaySnapshot(['source' => 'outside-preload']);

    $writer->preloadForPayrollPeriod($company->id, $period->id, [$employee->id], '2026-07-01', '2026-07-01', 1);
    $created = $writer->write($attributes, $snapshot);

    expect($writer->write($attributes, $snapshot)->id)->toBe($created->id)
        ->and(fn () => $writer->write($attributes, new PayrollDaySnapshot(['source' => 'outside-conflict'])))
        ->toThrow(LogicException::class, 'Conflicting immutable payroll result already exists.');
});

test('retries an in-scope preloaded write by hash and rejects a conflicting hash', function (): void {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $writer = app(PayrollDaySnapshotWriter::class);
    $attributes = snapshotWriterAttributes($company, $period, $employee, 1);
    $snapshot = new PayrollDaySnapshot(['source' => 'preloaded-retry']);

    $writer->preloadForPayrollPeriod($company->id, $period->id, [$employee->id], '2026-07-01', '2026-07-01', 1);
    $created = $writer->write($attributes, $snapshot);

    expect($writer->write($attributes, $snapshot)->id)->toBe($created->id)
        ->and(fn () => $writer->write($attributes, new PayrollDaySnapshot(['source' => 'preloaded-conflict'])))
        ->toThrow(LogicException::class, 'Conflicting immutable payroll result already exists.');
});
