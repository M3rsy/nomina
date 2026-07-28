<?php

use App\Jobs\ProcessOvertimeDecisionBatch;
use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\OvertimeDecisionBatch;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceReviewQuery;
use App\Services\Attendance\AttendanceSegment;
use App\Services\Attendance\AttendanceShiftAnalysis;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionBatchRequester;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\PayrollShiftReview;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    Queue::fake();
});
test('dispatches new and queued retries after commit but not completed batches', function () {
    $context = batchRequestFixture();
    $key = (string) Str::uuid();
    DB::transaction(function () use ($context, $key): void {
        requestBatch($context, ['key' => $key]);
        Queue::assertNothingPushed();
    });
    Queue::assertPushed(ProcessOvertimeDecisionBatch::class, 1);
    $batch = requestBatch($context, ['key' => $key]);
    Queue::assertPushed(ProcessOvertimeDecisionBatch::class, 2);
    $batch->update(['status' => OvertimeDecisionBatch::COMPLETED]);
    requestBatch($context, ['key' => $key]);
    Queue::assertPushed(ProcessOvertimeDecisionBatch::class, 2);
});
test('freezes authoritative pending candidates in a queued batch', function () {
    $context = batchRequestFixture();
    $batch = requestBatch($context);
    expect($batch->status)->toBe('queued')
        ->and($batch->total_items)->toBe(1)
        ->and($batch->items)->toHaveCount(1)
        ->and($batch->items->sole()->fingerprint)->toBe($context['candidate']->fingerprint)
        ->and($batch->items->sole()->status)->toBe('pending');
});
test('recorder safely reuses the decision linked to the same batch item', function () {
    $context = batchRequestFixture();
    $batch = requestBatch($context);
    $item = $batch->items->sole();
    $recorder = app(OvertimeDecisionRecorder::class);
    $arguments = [
        $context['period'], $context['employee'], '2026-07-20', $context['candidate']->key,
        OvertimeDecision::APPROVED, 'Cobertura extraordinaria confirmada', $context['actor'], $item->id,
    ];
    $first = $recorder->decide(...$arguments);
    expect($recorder->decide(...$arguments)->is($first))->toBeTrue()
        ->and($first->batch_item_id)->toBe($item->id)
        ->and(OvertimeDecision::query()->count())->toBe(1);
    $arguments[5] = 'Un motivo distinto';
    expect(fn () => $recorder->decide(...$arguments))->toThrow(LogicException::class)
        ->and(OvertimeDecision::query()->count())->toBe(1);
});
test('processes approved and rejected batches to completion', function (string $action) {
    $context = batchRequestFixture();
    $batch = requestBatch($context, ['decision' => $action]);
    (new ProcessOvertimeDecisionBatch($batch->id))->handle(app(OvertimeDecisionRecorder::class));
    expect($batch->fresh()->status)->toBe('completed')
        ->and($batch->fresh()->finished_at)->not->toBeNull()
        ->and($batch->items()->sole()->status)->toBe('succeeded')
        ->and($batch->items()->sole()->attempts)->toBe(1)
        ->and($batch->items()->sole()->decision->decision)->toBe($action);
})->with([OvertimeDecision::APPROVED, OvertimeDecision::REJECTED]);
test('continues after a stale item and reports partial completion', function () {
    $context = batchRequestFixture();
    $batch = requestBatch($context, ['targets' => [batchTarget($context), batchTarget(addBatchCandidate($context))]]);
    $context['exit_mark']->update(['event_at' => '2026-07-20 14:45:00', 'status' => 'corrected']);
    (new ProcessOvertimeDecisionBatch($batch->id))->handle(app(OvertimeDecisionRecorder::class));
    expect($batch->fresh()->status)->toBe('completed_with_errors')
        ->and($batch->items()->pluck('status')->sort()->values()->all())->toBe(['failed', 'succeeded'])
        ->and($batch->items()->where('status', 'failed')->sole()->last_error)->not->toBeNull()
        ->and(OvertimeDecision::query()->count())->toBe(1);
});
test('resumes after a decision was created before item completion without duplicates', function () {
    $context = batchRequestFixture();
    $batch = requestBatch($context);
    $item = $batch->items->sole();
    app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate']->key,
        $batch->decision, $batch->reason, $context['actor'], $item->id,
    );
    $job = new ProcessOvertimeDecisionBatch($batch->id);
    $job->handle(app(OvertimeDecisionRecorder::class));
    $job->handle(app(OvertimeDecisionRecorder::class));
    expect($batch->fresh()->status)->toBe('completed')
        ->and($item->fresh()->status)->toBe('succeeded')
        ->and($item->fresh()->attempts)->toBe(1)
        ->and(OvertimeDecision::query()->count())->toBe(1);
});
test('records revoked authorization as an item failure', function () {
    $context = batchRequestFixture();
    $batch = requestBatch($context);
    $context['actor']->update(['is_active' => false]);
    (new ProcessOvertimeDecisionBatch($batch->id))->handle(app(OvertimeDecisionRecorder::class));
    expect($batch->fresh()->status)->toBe('completed_with_errors')
        ->and($batch->items()->sole()->status)->toBe('failed')
        ->and(OvertimeDecision::query()->count())->toBe(0);
});
test('terminalizes exhausted infrastructure failures and retries the same request', function () {
    $context = batchRequestFixture();
    $key = (string) Str::uuid();
    $batch = requestBatch($context, ['key' => $key]);
    $recorder = Mockery::mock(OvertimeDecisionRecorder::class);
    $recorder->shouldReceive('decide')->once()->andThrow(new RuntimeException('database unavailable'));
    $job = new ProcessOvertimeDecisionBatch($batch->id);
    expect(fn () => $job->handle($recorder))
        ->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('database unavailable'));
    $job->handle(app(OvertimeDecisionRecorder::class));
    expect($batch->fresh()->status)->toBe('failed')
        ->and($batch->fresh()->finished_at)->not->toBeNull()
        ->and($batch->items()->sole()->status)->toBe('pending');
    $retry = requestBatch($context, ['key' => $key]);
    expect([$retry->status, $retry->finished_at, $retry->last_error])->toBe(['queued', null, null]);
    Queue::assertPushed(ProcessOvertimeDecisionBatch::class, 2);
});
test('processes bounded chunks and redispatches until terminal', function () {
    $context = batchRequestFixture();
    $targets = [batchTarget($context)];
    foreach (range(2, 21) as $_) {
        $targets[] = batchTarget(addBatchCandidate($context));
    }
    $batch = requestBatch($context, ['targets' => $targets]);
    $job = new ProcessOvertimeDecisionBatch($batch->id);
    $job->handle(app(OvertimeDecisionRecorder::class));
    expect($batch->items()->where('status', 'succeeded')->count())->toBe(20)
        ->and($batch->items()->where('status', 'pending')->count())->toBe(1);
    Queue::assertPushed(ProcessOvertimeDecisionBatch::class, 2);
    $job->handle(app(OvertimeDecisionRecorder::class));
    expect($batch->fresh()->status)->toBe('completed')
        ->and($batch->items()->where('status', 'succeeded')->count())->toBe(21);
});
test('returns the original batch for an exact idempotent retry', function () {
    $context = batchRequestFixture();
    $key = (string) Str::uuid();
    $first = requestBatch($context, ['key' => $key]);
    $reordered = [
        'candidate_key' => $context['candidate']->key,
        'work_date' => '2026-07-20',
        'employee_id' => $context['employee']->id,
    ];
    $retry = requestBatch($context, ['key' => $key, 'targets' => [$reordered, $reordered]]);
    expect($retry->is($first))->toBeTrue()
        ->and(OvertimeDecisionBatch::query()->count())->toBe(1);
});
test('rejects reuse of an idempotency key with a different payload', function () {
    $context = batchRequestFixture();
    $key = (string) Str::uuid();
    requestBatch($context, ['key' => $key, 'reason' => 'Motivo A']);
    expect(fn () => requestBatch($context, ['key' => $key, 'decision' => OvertimeDecision::REJECTED]))
        ->toThrow(ValidationException::class)
        ->and(OvertimeDecisionBatch::query()->count())->toBe(1);
});
test('rejects invalid request input without writing a batch', function (string $case) {
    $context = batchRequestFixture();
    $overrides = match ($case) {
        'decision' => ['decision' => 'pending'],
        'reason' => ['reason' => ' '],
        'key' => ['key' => 'not-a-uuid'],
        'targets' => ['targets' => []],
        'shape' => ['targets' => [[...batchTarget($context), 'fingerprint' => str_repeat('f', 64)]]],
    };
    expect(fn () => requestBatch($context, $overrides))
        ->toThrow(ValidationException::class)
        ->and(OvertimeDecisionBatch::query()->count())->toBe(0);
})->with(['decision', 'reason', 'key', 'targets', 'shape']);
test('atomically rejects stale, decided, or unknown candidates', function (string $case) {
    $context = batchRequestFixture();
    $targets = [batchTarget($context)];
    match ($case) {
        'stale' => $context['exit_mark']->update(['event_at' => '2026-07-20 14:45:00', 'status' => 'corrected']),
        'decided' => app(OvertimeDecisionRecorder::class)->decide(
            $context['period'], $context['employee'], '2026-07-20', $context['candidate']->key,
            OvertimeDecision::APPROVED, 'Ya decidido', $context['actor'],
        ),
        'unknown' => $targets[] = [...batchTarget($context), 'candidate_key' => str_repeat('0', 64)],
    };
    expect(fn () => requestBatch($context, ['targets' => $targets]))->toThrow(ValidationException::class)
        ->and(OvertimeDecisionBatch::query()->count())->toBe(0);
})->with(['stale', 'decided', 'unknown']);
test('requires an active authorized actor from the period company', function (string $case) {
    $context = batchRequestFixture();
    $actor = match ($case) {
        'inactive' => tap($context['actor'], fn (User $user) => $user->update(['is_active' => false])),
        'unauthorized' => User::factory()->forCompany($context['company'])->create(),
        'foreign' => User::factory()->forCompany(Company::factory()->create())->create()->assignRole('company_admin'),
    };
    expect(fn () => requestBatch($context, ['actor' => $actor]))->toThrow(AuthorizationException::class)
        ->and(OvertimeDecisionBatch::query()->count())->toBe(0);
})->with(['inactive', 'unauthorized', 'foreign']);
test('allows a super administrator to request a foreign-company batch', function () {
    $context = batchRequestFixture();
    $actor = User::factory()->create()->assignRole('super_admin');
    expect(requestBatch($context, ['actor' => $actor])->requested_by)->toBe($actor->id);
});
test('rejects a locked period without writing a batch', function (string $status) {
    $context = batchRequestFixture($status);
    expect(fn () => requestBatch($context))->toThrow(ValidationException::class)
        ->and(OvertimeDecisionBatch::query()->count())->toBe(0);
})->with(PayPeriod::ATTENDANCE_LOCKED_STATUSES);
test('queues only authoritative selected overtime candidates with stable idempotency', function (string $decision) {
    $context = batchRequestFixture();
    app(CurrentCompany::class)->set($context['company']);
    $this->actingAs($context['actor']);
    $token = implode('|', [$context['employee']->id, '2026-07-20', $context['candidate']->key]);
    $component = Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->set('selectedOvertimeCandidates', [[], null, 123, 'malformed'])
        ->call('openOvertimeBatch', $decision)->assertHasErrors(['selectedOvertimeCandidates'])
        ->set('selectedOvertimeCandidates', [$token, '999|2026-07-20|'.str_repeat('0', 64)])
        ->call('selectCurrentOvertimePage')
        ->assertSet('selectedOvertimeCandidates', [$token])
        ->call('openOvertimeBatch', $decision)
        ->assertSet('overtimeBatchCount', 1)
        ->assertSet('overtimeBatchSelection', fn ($value) => is_string($value) && strlen($value) === 64)
        ->call('saveOvertimeBatch')
        ->assertHasErrors(['overtimeBatchReason'])
        ->set('overtimeBatchReason', 'Cobertura extraordinaria confirmada');
    $requestKey = $component->get('overtimeBatchRequestKey');
    $context['exit_mark']->update(['event_at' => '2026-07-20 14:45:00']);
    $component->call('saveOvertimeBatch')->assertHasErrors(['selectedOvertimeCandidates'])
        ->assertSet('overtimeBatchRequestKey', $requestKey);
    $context['exit_mark']->update(['event_at' => '2026-07-20 14:30:00']);
    $component->call('saveOvertimeBatch')
        ->assertHasNoErrors()
        ->assertSet('selectedOvertimeCandidates', [])
        ->assertSet('overtimeBatchProgress.status', 'queued');
    expect(OvertimeDecisionBatch::query()->sole())
        ->request_key->toBe($requestKey)
        ->decision->toBe($decision)
        ->reason->toBe('Cobertura extraordinaria confirmada')
        ->total_items->toBe(1);
})->with([OvertimeDecision::APPROVED, OvertimeDecision::REJECTED]);
test('selects every filtered overtime match across pages with compact public state', function () {
    $context = batchRequestFixture();
    foreach (range(1, 25) as $_) {
        addBatchCandidate($context);
    }
    app(CurrentCompany::class)->set($context['company']);
    $this->actingAs($context['actor']);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('selectCurrentOvertimePage')
        ->assertCount('selectedOvertimeCandidates', 25)
        ->assertSee('Seleccionar los 26 resultados')
        ->call('selectAllFilteredOvertime')
        ->assertSet('allFilteredOvertimeSelected', true)
        ->assertSet('selectedOvertimeCandidates', [])
        ->assertSeeHtml('checked disabled')
        ->call('openOvertimeBatch', OvertimeDecision::APPROVED)
        ->assertSet('overtimeBatchCount', 26);

    expect($component->get('overtimeBatchSelection'))->toBeString()->toHaveLength(64);

    $component->set('overtimeBatchReason', 'Cobertura masiva confirmada')
        ->call('saveOvertimeBatch')
        ->assertHasNoErrors();

    expect(OvertimeDecisionBatch::query()->sole()->total_items)->toBe(26);
});
test('all-match batches are bounded by active filters and ignore forged selected tokens', function () {
    $context = batchRequestFixture();
    $target = addBatchCandidate($context);
    $otherFile = UploadedFile::factory()->forCompany($context['company'])->forPayPeriod($context['period'])->create();
    $otherFileCandidate = addBatchCandidate($context, $otherFile);
    app(CurrentCompany::class)->set($context['company']);
    $this->actingAs($context['actor']);

    $forgedTokens = [
        implode('|', [$context['employee']->id, '2026-07-20', $context['candidate']->key]),
        implode('|', [$otherFileCandidate['employee']->id, '2026-07-20', $otherFileCandidate['candidate']->key]),
    ];
    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->set('overtimeSearch', $target['employee']->external_id)
        ->set('overtimeStatus', 'pending')
        ->set('overtimeDate', '2026-07-20')
        ->set('overtimeRate', 'extra25')
        ->set('uploaded_file_id', $context['file']->id)
        ->call('selectAllFilteredOvertime')
        ->set('selectedOvertimeCandidates', $forgedTokens)
        ->call('openOvertimeBatch', OvertimeDecision::APPROVED)
        ->assertSet('overtimeBatchCount', 1)
        ->assertSee('Empleado: '.$target['employee']->external_id)
        ->assertSee('Archivo: '.$context['file']->original_name)
        ->set('overtimeBatchReason', 'Filtro auditado')
        ->call('saveOvertimeBatch')
        ->assertHasNoErrors();

    expect(OvertimeDecisionBatch::query()->sole()->items()->sole()->employee_id)
        ->toBe($target['employee']->id);
});
test('changing overtime filters clears all-match mode and clear returns to subset selection', function () {
    $context = batchRequestFixture();
    app(CurrentCompany::class)->set($context['company']);
    $this->actingAs($context['actor']);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('selectAllFilteredOvertime')
        ->assertSet('allFilteredOvertimeSelected', true)
        ->set('overtimeRate', 'extra25')
        ->assertSet('allFilteredOvertimeSelected', false)
        ->assertSet('selectedOvertimeCandidates', [])
        ->call('selectAllFilteredOvertime')
        ->call('clearOvertimeSelection')
        ->assertSet('allFilteredOvertimeSelected', false)
        ->call('selectCurrentOvertimePage');

    expect($component->get('selectedOvertimeCandidates'))->toHaveCount(1);
});
test('rejects more than 500 filtered overtime matches without creating a batch', function () {
    $context = batchRequestFixture();
    $review = app(AttendanceReviewQuery::class)->forPeriod($context['period'])->sole();
    $candidates = collect(range(1, 501))->map(fn (int $index) => new AttendanceSegment(
        $review->analysis->overtimeCandidates->sole()->kind,
        $review->analysis->overtimeCandidates->sole()->start,
        $review->analysis->overtimeCandidates->sole()->end,
        hash('sha256', "candidate-{$index}"),
        $review->analysis->overtimeCandidates->sole()->rateMinutes,
    ));
    $analysis = new AttendanceShiftAnalysis(
        $review->analysis->status, $review->analysis->workDate, $review->analysis->entryAt,
        $review->analysis->exitAt, $review->analysis->workedMinutes, $review->analysis->scheduledMinutes,
        $review->analysis->scheduledRates, $review->analysis->deficits, $candidates,
    );
    $bulkReview = new PayrollShiftReview(
        $review->employee, $review->occurrence, $analysis, collect(), collect(),
    );
    $query = Mockery::mock(AttendanceReviewQuery::class);
    $query->shouldReceive('forPeriod')->andReturn(collect([$bulkReview]));
    app()->instance(AttendanceReviewQuery::class, $query);
    app(CurrentCompany::class)->set($context['company']);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('selectAllFilteredOvertime')
        ->call('openOvertimeBatch', OvertimeDecision::APPROVED)
        ->assertHasErrors(['selectedOvertimeCandidates'])
        ->assertSee('Hay más de 500 candidatos pendientes. Aplique filtros más específicos antes de continuar.')
        ->assertSet('showOvertimeBatchModal', false);

    expect(OvertimeDecisionBatch::query()->count())->toBe(0);
});
test('all-match confirmation rejects candidate drift and filter changes before queueing', function () {
    $context = batchRequestFixture();
    app(CurrentCompany::class)->set($context['company']);
    $this->actingAs($context['actor']);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('selectAllFilteredOvertime')
        ->call('openOvertimeBatch', OvertimeDecision::APPROVED)
        ->set('overtimeBatchReason', 'Confirmación congelada');
    $context['exit_mark']->update(['event_at' => '2026-07-20 14:45:00']);
    $component->call('saveOvertimeBatch')
        ->assertHasErrors(['selectedOvertimeCandidates']);
    expect(OvertimeDecisionBatch::query()->count())->toBe(0);

    $context['exit_mark']->update(['event_at' => '2026-07-20 14:30:00']);
    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('selectAllFilteredOvertime')
        ->call('openOvertimeBatch', OvertimeDecision::APPROVED)
        ->set('overtimeSearch', 'otro empleado')
        ->assertSet('showOvertimeBatchModal', false)
        ->assertSet('allFilteredOvertimeSelected', false)
        ->call('saveOvertimeBatch');

    expect(OvertimeDecisionBatch::query()->count())->toBe(0);
});
test('resets overtime pagination and stale selection when its source or an individual decision changes', function () {
    $context = batchRequestFixture();
    app(CurrentCompany::class)->set($context['company']);
    $this->actingAs($context['actor']);
    $token = implode('|', [$context['employee']->id, '2026-07-20', $context['candidate']->key]);
    $component = Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->set('paginators.overtimePage', 2)->set('selectedOvertimeCandidates', [$token])
        ->set('uploaded_file_id', $context['file']->id)
        ->assertSet('paginators.overtimePage', 1)->assertSet('selectedOvertimeCandidates', [])
        ->set('paginators.overtimePage', 2)->set('selectedOvertimeCandidates', [$token])
        ->call('openOvertimeDecision', $context['employee']->id, '2026-07-20', $context['candidate']->key, 'approved')
        ->set('overtimeDecisionReason', 'Decisión individual')->call('saveOvertimeDecision');
    $component->assertSet('paginators.overtimePage', 1)->assertSet('selectedOvertimeCandidates', []);
});
test('polls only the actors period batch and reports bounded terminal errors', function () {
    $context = batchRequestFixture();
    $second = addBatchCandidate($context);
    $batch = requestBatch($context, ['targets' => [batchTarget($context), batchTarget($second)]]);
    $batch->update(['status' => 'processing']);
    app(CurrentCompany::class)->set($context['company']);
    $this->actingAs($context['actor']);
    $component = Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertSet('activeOvertimeBatchId', $batch->id)
        ->set('paginators.overtimePage', 2)->call('pollOvertimeBatch')
        ->assertSet('overtimeBatchProgress.status', 'processing');
    $items = $batch->items()->orderBy('id')->get();
    $items[0]->update(['status' => 'succeeded']);
    $items[1]->update(['status' => 'failed', 'last_error' => 'Las marcas cambiaron.']);
    $batch->update(['status' => 'completed_with_errors', 'finished_at' => now()]);
    $component->call('pollOvertimeBatch')
        ->assertSet('overtimeBatchProgress.failed', 1)
        ->assertSet('overtimeBatchProgress.terminal', true)
        ->assertSet('paginators.overtimePage', 1)
        ->assertSet('overtimeBatchErrors.0', 'Las marcas cambiaron.');
    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])->assertSet('activeOvertimeBatchId', null);
    $foreignCompany = Company::factory()->create();
    $batch->update(['company_id' => $foreignCompany->id,
        'pay_period_id' => PayPeriod::factory()->forCompany($foreignCompany)->create()->id,
        'requested_by' => User::factory()->forCompany($foreignCompany)->create()->id]);
    $component->call('pollOvertimeBatch')->assertSet('activeOvertimeBatchId', null);
});
function requestBatch(array $context, array $overrides = []): OvertimeDecisionBatch
{
    return app(OvertimeDecisionBatchRequester::class)->request(
        $context['period'],
        $overrides['targets'] ?? [batchTarget($context)],
        $overrides['decision'] ?? OvertimeDecision::APPROVED,
        $overrides['reason'] ?? 'Cobertura extraordinaria confirmada',
        $overrides['actor'] ?? $context['actor'],
        $overrides['key'] ?? (string) Str::uuid(),
    );
}
function batchTarget(array $context): array
{
    return ['employee_id' => $context['employee']->id, 'work_date' => '2026-07-20', 'candidate_key' => $context['candidate']->key];
}
function batchRequestFixture(string $periodStatus = 'uploaded'): array
{
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1, 'start_time' => '06:00', 'end_time' => '14:00', 'base_ordinary_hours' => 8,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada diurna');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20', 'end_date' => '2026-07-20', 'status' => $periodStatus,
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $marks = collect(['2026-07-20 06:00:00', '2026-07-20 14:30:00'])
        ->map(fn (string $eventAt) => RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']));
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $candidate = app(AttendanceShiftAnalyzer::class)
        ->analyze(app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20'))
        ->overtimeCandidates->sole();

    return compact('company', 'profile', 'employee', 'period', 'file', 'actor', 'candidate') + ['exit_mark' => $marks->last()];
}
function addBatchCandidate(array $context, ?UploadedFile $file = null): array
{
    $employee = Employee::factory()->forCompany($context['company'])->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $context['profile'], '2026-07-01', 'Jornada diurna');
    $marks = collect(['2026-07-20 06:00:00', '2026-07-20 15:00:00'])
        ->map(fn (string $eventAt) => RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
            ->forUploadedFile($file ?? $context['file'])->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']));
    $candidate = app(AttendanceShiftAnalyzer::class)
        ->analyze(app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20'))->overtimeCandidates->sole();

    return compact('employee', 'candidate') + ['exit_mark' => $marks->last()];
}
