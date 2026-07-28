<?php

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
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionBatchRequester;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\ShiftOccurrenceResolver;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(PermissionRoleSeeder::class));
test('freezes authoritative pending candidates in a queued batch', function () {
    $context = batchRequestFixture();
    $batch = requestBatch($context);
    expect($batch->status)->toBe('queued')
        ->and($batch->total_items)->toBe(1)
        ->and($batch->items)->toHaveCount(1)
        ->and($batch->items->sole()->fingerprint)->toBe($context['candidate']->fingerprint)
        ->and($batch->items->sole()->status)->toBe('pending');
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

    return compact('company', 'employee', 'period', 'actor', 'candidate') + ['exit_mark' => $marks->last()];
}
