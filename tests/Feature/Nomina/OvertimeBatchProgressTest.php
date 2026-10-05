<?php

use App\Livewire\Nomina\OvertimeBatchProgress;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\OvertimeDecisionBatch;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

beforeEach(function () {
    /** @var TestCase $this */
    $this->seed(PermissionRoleSeeder::class);
});

test('progress shell remains empty and skips batch queries without an active batch', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);
    $this->actingAs($admin);

    $component = Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period])
        ->assertSet('batchId', null)
        ->assertSet('progress', [])
        ->assertDontSee('Decisión masiva')
        ->assertDontSeeHtml('wire:poll.3s="poll"');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->call('poll');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(collect($queries)->contains(
        fn (array $query): bool => str_contains($query['query'], 'overtime_decision_batches'),
    ))->toBeFalse();
});

test('progress shell starts polling from the batch start event', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create();
    $batch = OvertimeDecisionBatch::withoutCompanyScope()->create([
        'request_key' => (string) Str::uuid(),
        'payload_hash' => str_repeat('a', 64),
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'requested_by' => $admin->id,
        'decision' => OvertimeDecision::APPROVED,
        'reason' => 'Approved after review',
        'status' => OvertimeDecisionBatch::QUEUED,
        'total_items' => 1,
    ]);
    $batch->items()->create([
        'employee_id' => $employee->id,
        'work_date' => $period->start_date->toDateString(),
        'candidate_key' => str_repeat('b', 64),
        'fingerprint' => str_repeat('c', 64),
        'status' => 'pending',
    ]);
    app(CurrentCompany::class)->set($company);
    $this->actingAs($admin);

    Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period])
        ->assertSet('batchId', null)
        ->dispatch('overtime-batch-started', batchId: $batch->id)
        ->assertSet('batchId', $batch->id)
        ->assertSet('progress.status', OvertimeDecisionBatch::QUEUED)
        ->assertSet('progress.pending', 1)
        ->assertSee("Lote #{$batch->id}")
        ->assertSeeHtml('wire:poll.3s="poll"');
});
