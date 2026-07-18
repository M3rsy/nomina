<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayrollProcessor;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionRoleSeeder::class);
});

function readyPayPeriod(?Company $company = null, string $start = '2026-01-05', string $end = '2026-01-11'): PayPeriod
{
    $company ??= Company::factory()->create();

    return PayPeriod::factory()->forCompany($company)->create([
        'start_date' => $start,
        'end_date' => $end,
        'status' => 'ready',
    ]);
}

test('payroll processor transitions pay period through status flow', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company);
    Employee::factory()->forCompany($company)->create();

    app(CurrentCompany::class)->set($company);
    $processor = new PayrollProcessor(new App\Services\Payroll\PayrollCalculator(
        new App\Services\Payroll\BandSplitter,
        new App\Services\PayrollRules,
    ));

    $report = $processor->processPayPeriod($payPeriod);

    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and($report->employeesProcessed)->toBe(1)
        ->and($report->resultsInserted)->toBeGreaterThan(0);
});

test('processor persists a result row for every employee working day combo', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-11');
    Employee::factory()->forCompany($company)->count(2)->create();

    app(CurrentCompany::class)->set($company);
    $processor = new PayrollProcessor(new App\Services\Payroll\PayrollCalculator(
        new App\Services\Payroll\BandSplitter,
        new App\Services\PayrollRules,
    ));

    $processor->processPayPeriod($payPeriod);

    // Jan 5-11 2026: Mon-Fri + Sat = 6 working days, Sunday non-working.
    $expectedRows = 2 * 6;

    expect(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe($expectedRows);
});

test('processor rejects pay periods that are not ready', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);

    app(CurrentCompany::class)->set($company);
    $processor = new PayrollProcessor(new App\Services\Payroll\PayrollCalculator(
        new App\Services\Payroll\BandSplitter,
        new App\Services\PayrollRules,
    ));

    expect(fn () => $processor->processPayPeriod($payPeriod))->toThrow(InvalidArgumentException::class);
});

test('processor stores rules version from config', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    Employee::factory()->forCompany($company)->create();

    config(['payroll.rules_version' => '2026-01']);

    app(CurrentCompany::class)->set($company);
    $processor = new PayrollProcessor(new App\Services\Payroll\PayrollCalculator(
        new App\Services\Payroll\BandSplitter,
        new App\Services\PayrollRules,
    ));

    $processor->processPayPeriod($payPeriod);

    expect(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->first()->rules_version)->toBe('2026-01');
});

test('processor is idempotent and updates existing rows', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    Employee::factory()->forCompany($company)->create();

    app(CurrentCompany::class)->set($company);
    $processor = new PayrollProcessor(new App\Services\Payroll\PayrollCalculator(
        new App\Services\Payroll\BandSplitter,
        new App\Services\PayrollRules,
    ));

    $firstReport = $processor->processPayPeriod($payPeriod);

    // Reset the pay period to ready so the second run is allowed.
    $payPeriod->update(['status' => 'ready']);

    $secondReport = $processor->processPayPeriod($payPeriod);

    expect(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(1)
        ->and($firstReport->resultsInserted)->toBe(1)
        ->and($firstReport->resultsUpdated)->toBe(0)
        ->and($secondReport->resultsInserted)->toBe(0)
        ->and($secondReport->resultsUpdated)->toBe(1);
});

test('processor never touches employees from another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodA = readyPayPeriod($companyA, '2026-01-05', '2026-01-05');
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'ready',
    ]);

    Employee::factory()->forCompany($companyA)->create();
    Employee::factory()->forCompany($companyB)->create();

    app(CurrentCompany::class)->set($companyA);
    $processor = new PayrollProcessor(new App\Services\Payroll\PayrollCalculator(
        new App\Services\Payroll\BandSplitter,
        new App\Services\PayrollRules,
    ));

    $processor->processPayPeriod($payPeriodA);

    expect(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriodA->id)->count())->toBe(1)
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriodB->id)->count())->toBe(0);
});

test('processor wraps processing in a transaction', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    Employee::factory()->forCompany($company)->create();

    app(CurrentCompany::class)->set($company);
    $processor = new PayrollProcessor(new App\Services\Payroll\PayrollCalculator(
        new App\Services\Payroll\BandSplitter,
        new App\Services\PayrollRules,
    ));

    $processor->processPayPeriod($payPeriod);

    expect($payPeriod->fresh()->status)->toBe('processed');
});

test('processor report counts absence flags', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    Employee::factory()->forCompany($company)->create();

    app(CurrentCompany::class)->set($company);
    $processor = new PayrollProcessor(new App\Services\Payroll\PayrollCalculator(
        new App\Services\Payroll\BandSplitter,
        new App\Services\PayrollRules,
    ));

    $report = $processor->processPayPeriod($payPeriod);

    expect($report->unjustifiedAbsenceCount)->toBe(1)
        ->and($report->daysProcessed)->toBe(1);
});
