<?php

use App\Jobs\ProcessPayrollRun;
use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\StartPayrollProcessing;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    Queue::fake();
});

test('atomically marks a reviewed period ready and reserves its payroll run', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'validating']);

    $run = app(StartPayrollProcessing::class)->start($period, $actor, (string) Str::uuid());

    expect($period->fresh()->status)->toBe('ready')
        ->and($run->status)->toBe(PayrollRun::QUEUED)
        ->and($run->pay_period_id)->toBe($period->id)
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(1);
    Queue::assertPushed(ProcessPayrollRun::class, fn ($job) => true);
});

test('replaying the same start request does not reserve a second run', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $key = (string) Str::uuid();

    $first = app(StartPayrollProcessing::class)->start($period, $actor, $key);
    $replay = app(StartPayrollProcessing::class)->start($period->fresh(), $actor, $key);

    expect($replay->is($first))->toBeTrue()
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(1);
    Queue::assertPushed(ProcessPayrollRun::class, 1);
});
