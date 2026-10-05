<?php

use App\Livewire\Nomina\OvertimeBatchProgress;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\OvertimeDecisionBatch;
use App\Models\OvertimeDecisionBatchItem;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

beforeEach(function () {
    /** @var TestCase $this */
    $this->seed(PermissionRoleSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
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
    ['period' => $period, 'batch' => $batch] = overtimeBatchProgressFixture($this);

    Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period])
        ->assertSet('batchId', null)
        ->dispatch('overtime-batch-started', batchId: $batch->id)
        ->assertSet('batchId', $batch->id)
        ->assertSet('progress.status', OvertimeDecisionBatch::QUEUED)
        ->assertSet('progress.pending', 1)
        ->assertSee("Lote #{$batch->id}")
        ->assertSeeHtml('wire:poll.3s="poll"');
});

test('a recent queued batch reports activity without a delay warning', function () {
    /** @var TestCase $this */
    Carbon::setTestNow('2026-10-04 10:00:00');
    ['period' => $period, 'batch' => $batch, 'item' => $item] = overtimeBatchProgressFixture($this);
    setOvertimeBatchProgressTimestamps($batch->id, $item->id, [
        'created_at' => '2026-10-04 09:59:30',
        'updated_at' => '2026-10-04 09:59:30',
    ], '2026-10-04 09:59:30');

    Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period, 'batchId' => $batch->id])
        ->assertSet('progress.status', OvertimeDecisionBatch::QUEUED)
        ->assertSet('progress.delayed', false)
        ->assertSet('progress.delay_reason', null)
        ->assertSet('progress.created_at', '2026-10-04T09:59:30-06:00')
        ->assertSet('progress.latest_activity_at', '2026-10-04T09:59:30-06:00')
        ->assertSee('En cola')
        ->assertDontSee('No hubo actividad reciente');
});

test('a queued batch is marked delayed after thirty seconds without activity', function () {
    /** @var TestCase $this */
    Carbon::setTestNow('2026-10-04 10:00:31');
    ['period' => $period, 'batch' => $batch, 'item' => $item] = overtimeBatchProgressFixture($this);
    setOvertimeBatchProgressTimestamps($batch->id, $item->id, [
        'created_at' => '2026-10-04 10:00:00',
        'updated_at' => '2026-10-04 10:00:00',
    ], '2026-10-04 10:00:00');

    Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period, 'batchId' => $batch->id])
        ->assertSet('progress.delayed', true)
        ->assertSet('progress.delay_reason', 'queued_without_recent_activity')
        ->assertSee('En cola')
        ->assertSee('No hubo actividad reciente')
        ->assertSee('verificá el worker de la cola')
        ->assertSeeHtml('wire:poll.3s="poll"');
});

test('a processing batch uses latest item activity to determine delay', function () {
    /** @var TestCase $this */
    Carbon::setTestNow('2026-10-04 10:01:01');
    ['period' => $period, 'batch' => $batch, 'item' => $item] = overtimeBatchProgressFixture($this);
    setOvertimeBatchProgressTimestamps($batch->id, $item->id, [
        'status' => OvertimeDecisionBatch::PROCESSING,
        'created_at' => '2026-10-04 09:59:00',
        'started_at' => '2026-10-04 10:00:00',
        'updated_at' => '2026-10-04 10:00:10',
    ], '2026-10-04 10:00:30', 'processing');

    Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period, 'batchId' => $batch->id])
        ->assertSet('progress.status', OvertimeDecisionBatch::PROCESSING)
        ->assertSet('progress.latest_activity_at', '2026-10-04T10:00:30-06:00')
        ->assertSet('progress.delayed', true)
        ->assertSet('progress.delay_reason', 'processing_without_recent_activity')
        ->assertSee('En proceso')
        ->assertSee('No hubo actividad reciente');
});

test('a terminal batch is never delayed and shows lifecycle timestamps', function () {
    /** @var TestCase $this */
    Carbon::setTestNow('2026-10-04 11:00:00');
    ['period' => $period, 'batch' => $batch, 'item' => $item] = overtimeBatchProgressFixture($this);
    setOvertimeBatchProgressTimestamps($batch->id, $item->id, [
        'status' => OvertimeDecisionBatch::COMPLETED,
        'created_at' => '2026-10-04 10:00:00',
        'started_at' => '2026-10-04 10:01:00',
        'finished_at' => '2026-10-04 10:02:00',
        'updated_at' => '2026-10-04 10:02:00',
    ], '2026-10-04 10:01:45', 'succeeded');

    $component = Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period, 'batchId' => $batch->id])
        ->assertSet('progress.terminal', true)
        ->assertSet('progress.delayed', false)
        ->assertSet('progress.delay_reason', null)
        ->assertSet('progress.created_at', '2026-10-04T10:00:00-06:00')
        ->assertSet('progress.started_at', '2026-10-04T10:01:00-06:00')
        ->assertSet('progress.finished_at', '2026-10-04T10:02:00-06:00')
        ->assertSet('progress.latest_activity_at', '2026-10-04T10:02:00-06:00')
        ->assertSee('Completado')
        ->assertSee('04/10/2026 10:00:00')
        ->assertSee('04/10/2026 10:01:00')
        ->assertSee('04/10/2026 10:02:00')
        ->assertDontSee('No hubo actividad reciente')
        ->assertDontSeeHtml('wire:poll.3s="poll"')
        ->assertDispatched('overtime-batch-terminal', batchId: $batch->id);

    $component->call('poll')
        ->assertNotDispatched('overtime-batch-terminal');
});

test('batch last error is exposed and visible', function () {
    /** @var TestCase $this */
    Carbon::setTestNow('2026-10-04 11:00:00');
    ['period' => $period, 'batch' => $batch, 'item' => $item] = overtimeBatchProgressFixture($this);
    setOvertimeBatchProgressTimestamps($batch->id, $item->id, [
        'status' => 'failed',
        'last_error' => 'La cola rechazó el lote antes de procesarlo.',
        'created_at' => '2026-10-04 10:00:00',
        'finished_at' => '2026-10-04 10:00:10',
        'updated_at' => '2026-10-04 10:00:10',
    ], '2026-10-04 10:00:00');

    Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period, 'batchId' => $batch->id])
        ->assertSet('progress.last_error', 'La cola rechazó el lote antes de procesarlo.')
        ->assertSet('progress.delayed', false)
        ->assertSee('Fallido')
        ->assertSee('La cola rechazó el lote antes de procesarlo.')
        ->assertDontSee('El lote no pudo completarse. Puede intentarlo nuevamente.');
});

test('poll derives latest item activity from the grouped status query', function () {
    /** @var TestCase $this */
    ['period' => $period, 'batch' => $batch] = overtimeBatchProgressFixture($this);
    $component = Livewire::test(OvertimeBatchProgress::class, ['payPeriod' => $period, 'batchId' => $batch->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->call('poll');
    $itemQueries = collect(DB::getQueryLog())->filter(
        fn (array $query): bool => str_contains($query['query'], 'overtime_decision_batch_items'),
    );
    DB::disableQueryLog();

    expect($itemQueries)->toHaveCount(2)
        ->and($itemQueries->contains(
            fn (array $query): bool => str_contains($query['query'], 'max(updated_at) as latest_activity_at'),
        ))->toBeTrue();
});

/**
 * @return array{period: PayPeriod, batch: OvertimeDecisionBatch, item: OvertimeDecisionBatchItem}
 */
function overtimeBatchProgressFixture(TestCase $testCase): array
{
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
    $item = $batch->items()->create([
        'employee_id' => $employee->id,
        'work_date' => $period->start_date->toDateString(),
        'candidate_key' => str_repeat('b', 64),
        'fingerprint' => str_repeat('c', 64),
        'status' => 'pending',
    ]);
    app(CurrentCompany::class)->set($company);
    $testCase->actingAs($admin);

    return compact('period', 'batch', 'item');
}

/**
 * @param  array<string, mixed>  $batchTimestamps
 */
function setOvertimeBatchProgressTimestamps(
    int $batchId,
    int $itemId,
    array $batchTimestamps,
    string $itemUpdatedAt,
    string $itemStatus = 'pending',
): void {
    DB::table('overtime_decision_batches')->where('id', $batchId)->update($batchTimestamps);
    DB::table('overtime_decision_batch_items')->where('id', $itemId)->update([
        'status' => $itemStatus,
        'updated_at' => $itemUpdatedAt,
    ]);
}
