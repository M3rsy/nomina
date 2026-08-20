<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\AttendanceDecisionAppender;
use App\Services\Attendance\AttendanceSegment;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\Payroll\BandSplit;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function overtimeSqlState(Closure $operation): ?string
{
    try {
        $operation();
    } catch (QueryException $exception) {
        return $exception->getCode();
    }

    return null;
}

/** @return array{parent:OvertimeDecision,current:AttendanceSegment,attributes:array<string,mixed>} */
function compatibleV1OvertimeTransition(): array
{
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $rates = new BandSplit(ordinaryMinutes: 0, extra25Minutes: 30);
    $released = new AttendanceSegment(
        'post_shift', CarbonImmutable::parse('2026-07-20 14:00:00'), CarbonImmutable::parse('2026-07-20 14:30:00'),
        str_repeat('2', 64), $rates,
    );
    $current = (new AttendanceSegment(
        'post_shift', CarbonImmutable::parse('2026-07-20 14:00:00'), CarbonImmutable::parse('2026-07-20 14:30:00'),
        str_repeat('3', 64), $rates,
    ))->withCompatibleIdentities($released->identities());
    $parentId = DB::table('overtime_decisions')->insertGetId([
        'record_version' => 1, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'candidate_key' => $released->key,
        'fingerprint' => $released->fingerprint, 'segment_kind' => $released->kind,
        'starts_at' => $released->start, 'ends_at' => $released->end, 'minutes' => 30,
        'rate_minutes' => json_encode($released->identity->rateMinutes, JSON_THROW_ON_ERROR),
        'decision' => 'approved', 'reason' => 'Approved released identity', 'decided_by' => $actor->id,
        'created_at' => now(),
    ]);

    return [
        'parent' => OvertimeDecision::withoutCompanyScope()->findOrFail($parentId),
        'current' => $current,
        'attributes' => [
            'record_version' => 1, 'company_id' => $company->id, 'pay_period_id' => $period->id,
            'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'candidate_key' => $current->key,
            'fingerprint' => $current->fingerprint, 'segment_kind' => $current->kind,
            'starts_at' => $current->start, 'ends_at' => $current->end, 'minutes' => 30,
            'rate_minutes' => $current->identity->rateMinutes, 'decision' => 'rejected',
            'reason' => 'Rejected canonical identity', 'decided_by' => $actor->id,
            'supersedes_id' => $parentId, 'created_at' => now(),
        ],
    ];
}

test('enforces conserved append-only V2 overtime resolutions while preserving valid V1 rows', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $candidateRates = ['ordinary' => 0, 'extra25' => 60, 'extra50' => 60, 'extra75' => 0, 'extra100' => 0];
    $partialRates = ['ordinary' => 0, 'extra25' => 30, 'extra50' => 30, 'extra75' => 0, 'extra100' => 0];
    $base = fn (array $overrides = []): array => array_replace([
        'record_version' => 2, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'candidate_key' => str_repeat('a', 64),
        'fingerprint' => str_repeat('b', 64), 'segment_kind' => 'post_quota_overtime',
        'starts_at' => '2026-07-20 17:00:00', 'ends_at' => '2026-07-20 19:00:00', 'minutes' => 120,
        'rate_minutes' => json_encode($candidateRates, JSON_THROW_ON_ERROR), 'decision' => 'approved',
        'reason' => 'Approved exact interval', 'decided_by' => $actor->id, 'supersedes_id' => null,
        'resolution_kind' => 'partial', 'approved_starts_at' => '2026-07-20 17:30:00',
        'approved_ends_at' => '2026-07-20 18:30:00', 'rejected_before_starts_at' => '2026-07-20 17:00:00',
        'rejected_before_ends_at' => '2026-07-20 17:30:00', 'rejected_after_starts_at' => '2026-07-20 18:30:00',
        'rejected_after_ends_at' => '2026-07-20 19:00:00', 'approved_minutes' => 60, 'rejected_minutes' => 60,
        'rejected_before_minutes' => 30, 'rejected_after_minutes' => 30,
        'approved_rate_minutes' => json_encode($partialRates, JSON_THROW_ON_ERROR),
        'rejected_rate_minutes' => json_encode($partialRates, JSON_THROW_ON_ERROR),
        'resolution_hash' => str_repeat('c', 64), 'created_at' => now(),
    ], $overrides);
    DB::table('overtime_decisions')->insert($base([
        'record_version' => 1, 'candidate_key' => str_repeat('d', 64), 'segment_kind' => 'post_shift',
        'minutes' => 30, 'starts_at' => '2026-07-20 14:00:00', 'ends_at' => '2026-07-20 14:30:00',
        'rate_minutes' => json_encode(array_replace($candidateRates, ['extra25' => 30, 'extra50' => 0]), JSON_THROW_ON_ERROR),
        'resolution_kind' => null, 'approved_starts_at' => null, 'approved_ends_at' => null,
        'rejected_before_starts_at' => null, 'rejected_before_ends_at' => null,
        'rejected_after_starts_at' => null, 'rejected_after_ends_at' => null,
        'approved_minutes' => null, 'rejected_minutes' => null, 'rejected_before_minutes' => null,
        'rejected_after_minutes' => null, 'approved_rate_minutes' => null, 'rejected_rate_minutes' => null,
        'resolution_hash' => null,
    ]));
    $partialId = DB::table('overtime_decisions')->insertGetId($base());
    $duplicateRoot = overtimeSqlState(fn () => DB::table('overtime_decisions')->insert($base([
        'resolution_hash' => str_repeat('e', 64),
    ])));

    $missingScalar = overtimeSqlState(fn () => DB::table('overtime_decisions')->insert($base([
        'approved_minutes' => null, 'resolution_hash' => str_repeat('e', 64),
    ])));
    $missingBoundary = overtimeSqlState(fn () => DB::table('overtime_decisions')->insert($base([
        'approved_starts_at' => null, 'resolution_hash' => str_repeat('e', 64),
    ])));
    $badConservation = overtimeSqlState(fn () => DB::table('overtime_decisions')->insert($base([
        'approved_minutes' => 61, 'resolution_hash' => str_repeat('e', 64),
    ])));
    $badNegativeRate = overtimeSqlState(fn () => DB::table('overtime_decisions')->insert($base([
        'approved_rate_minutes' => json_encode(array_replace($partialRates, ['extra25' => -1, 'extra50' => 61]), JSON_THROW_ON_ERROR),
        'rejected_rate_minutes' => json_encode(array_replace($partialRates, ['extra25' => 61, 'extra50' => -1]), JSON_THROW_ON_ERROR),
        'resolution_hash' => str_repeat('e', 64),
    ])));
    $badParent = overtimeSqlState(fn () => DB::table('overtime_decisions')->insert($base([
        'candidate_key' => str_repeat('f', 64), 'supersedes_id' => $partialId,
        'resolution_hash' => str_repeat('f', 64),
    ])));
    $mutation = overtimeSqlState(fn () => DB::table('overtime_decisions')->where('id', $partialId)->update(['reason' => 'Changed']));
    $deletion = overtimeSqlState(fn () => DB::table('overtime_decisions')->where('id', $partialId)->delete());

    expect($duplicateRoot)->toBe('23505')
        ->and($missingScalar)->toBe('23514')
        ->and($missingBoundary)->toBe('23514')
        ->and($badConservation)->toBe('23514')
        ->and($badNegativeRate)->toBe('23514')
        ->and($badParent)->toBe('23514')
        ->and($mutation)->toBe('23514')
        ->and($deletion)->toBe('23514')
        ->and(DB::table('overtime_decisions')->where('record_version', 2)->whereNull('supersedes_id')->count())->toBe(1)
        ->and(DB::table('overtime_decisions')->count())->toBe(2);
});

test('records valid V2 whole approval and rejection through the public recorder path', function () {
    $this->seed(PermissionRoleSeeder::class);
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $profile = WorkScheduleProfile::withoutEvents(
        fn () => WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']),
    );
    WorkScheduleProfilePublication::withoutCompanyScope()->create([
        'company_id' => $company->id, 'profile_key' => 'general', 'profile_id' => $profile->id,
        'payroll_policy_key' => 'duration-first-v2', 'effective_from' => '2026-07-01',
        'definition_hash' => str_repeat('1', 64), 'request_key' => str_repeat('2', 64),
        'payload_hash' => str_repeat('3', 64), 'reason' => 'PostgreSQL public-path fixture',
        'published_by' => $actor->id,
    ]);
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1, 'start_time' => '06:00', 'end_time' => '14:00',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'General schedule');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20', 'end_date' => '2026-07-20', 'status' => 'uploaded',
    ]);
    foreach (['2026-07-20 09:00:00', '2026-07-20 19:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)
            ->create(['event_at' => $eventAt, 'status' => 'valid']);
    }
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    $candidate = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $recorder = app(OvertimeDecisionRecorder::class);

    $approved = $recorder->decide(
        $period, $employee, '2026-07-20', $candidate->key,
        OvertimeDecision::APPROVED, 'Approve whole candidate', $actor,
    );
    $rejected = $recorder->decide(
        $period, $employee, '2026-07-20', $candidate->key,
        OvertimeDecision::REJECTED, 'Reject whole candidate', $actor,
    );

    expect([$approved->resolution_kind, $approved->approved_minutes, $approved->rejected_minutes])
        ->toBe(['whole_approve', 120, 0])
        ->and([$rejected->resolution_kind, $rejected->approved_minutes, $rejected->rejected_minutes])
        ->toBe(['whole_reject', 0, 120])
        ->and([$rejected->rejected_before_minutes, $rejected->rejected_after_minutes])->toBe([0, 0])
        ->and($rejected->supersedes_id)->toBe($approved->id)
        ->and(OvertimeDecision::withoutCompanyScope()->current()->sole()->is($rejected))->toBeTrue();
});

test('rejects a shape-only V2 overtime supersession without verified predecessor authorization', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $rates = ['ordinary' => 0, 'extra25' => 30, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0];
    $zeroRates = array_fill_keys(array_keys($rates), 0);
    $context = [
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-07-20',
        'starts_at' => '2026-07-20 14:00:00',
        'ends_at' => '2026-07-20 14:30:00',
        'minutes' => 30,
        'rate_minutes' => json_encode($rates, JSON_THROW_ON_ERROR),
        'decision' => 'approved',
        'reason' => 'Approved exact interval',
        'decided_by' => $actor->id,
        'created_at' => now(),
    ];
    $legacyId = DB::table('overtime_decisions')->insertGetId(array_replace($context, [
        'record_version' => 1,
        'candidate_key' => str_repeat('a', 64),
        'fingerprint' => str_repeat('b', 64),
        'segment_kind' => 'post_shift',
    ]));

    $state = overtimeSqlState(fn () => DB::table('overtime_decisions')->insert(array_replace($context, [
        'record_version' => 2,
        'candidate_key' => str_repeat('c', 64),
        'fingerprint' => str_repeat('d', 64),
        'segment_kind' => 'post_quota_overtime',
        'supersedes_id' => $legacyId,
        'resolution_kind' => 'whole_approve',
        'approved_starts_at' => $context['starts_at'],
        'approved_ends_at' => $context['ends_at'],
        'rejected_before_starts_at' => null,
        'rejected_before_ends_at' => null,
        'rejected_after_starts_at' => null,
        'rejected_after_ends_at' => null,
        'approved_minutes' => 30,
        'rejected_minutes' => 0,
        'rejected_before_minutes' => 0,
        'rejected_after_minutes' => 0,
        'approved_rate_minutes' => json_encode($rates, JSON_THROW_ON_ERROR),
        'rejected_rate_minutes' => json_encode($zeroRates, JSON_THROW_ON_ERROR),
        'resolution_hash' => str_repeat('e', 64),
    ])));

    expect($state)->toBe('23514')
        ->and(DB::table('overtime_decisions')->count())->toBe(1);
});

test('accepts one exact matcher-compatible V1 overtime supersession authorization', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $rates = new BandSplit(ordinaryMinutes: 0, extra25Minutes: 30);
    $legacy = new AttendanceSegment(
        'post_shift',
        CarbonImmutable::parse('2026-07-20 14:00:00'),
        CarbonImmutable::parse('2026-07-20 14:30:00'),
        str_repeat('a', 64),
        $rates,
    );
    $current = (new AttendanceSegment(
        'post_quota_overtime',
        CarbonImmutable::parse('2026-07-20 14:00:00'),
        CarbonImmutable::parse('2026-07-20 14:30:00'),
        str_repeat('b', 64),
        $rates,
    ))->withCompatibleIdentities($legacy->identities());
    $rateArray = $current->identity->rateMinutes;
    $zeroRateArray = array_fill_keys(array_keys($rateArray), 0);
    $rateMinutes = json_encode($rateArray, JSON_THROW_ON_ERROR);
    $legacyId = DB::table('overtime_decisions')->insertGetId([
        'record_version' => 1, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'candidate_key' => $legacy->key,
        'fingerprint' => $legacy->fingerprint, 'segment_kind' => $legacy->kind,
        'starts_at' => $legacy->start, 'ends_at' => $legacy->end, 'minutes' => 30,
        'rate_minutes' => $rateMinutes, 'decision' => 'approved', 'reason' => 'Approved legacy fact',
        'decided_by' => $actor->id, 'created_at' => now(),
    ]);
    $parent = OvertimeDecision::withoutCompanyScope()->findOrFail($legacyId);
    $attributes = [
        'record_version' => 2, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'candidate_key' => $current->key,
        'fingerprint' => $current->fingerprint, 'segment_kind' => $current->kind,
        'starts_at' => $current->start, 'ends_at' => $current->end, 'minutes' => 30,
        'rate_minutes' => $rateArray, 'decision' => 'approved', 'reason' => 'Approved canonical fact',
        'decided_by' => $actor->id, 'supersedes_id' => $legacyId, 'resolution_kind' => 'whole_approve',
        'approved_starts_at' => $current->start, 'approved_ends_at' => $current->end,
        'rejected_before_starts_at' => null, 'rejected_before_ends_at' => null,
        'rejected_after_starts_at' => null, 'rejected_after_ends_at' => null,
        'approved_minutes' => 30, 'rejected_minutes' => 0,
        'rejected_before_minutes' => 0, 'rejected_after_minutes' => 0,
        'approved_rate_minutes' => $rateArray, 'rejected_rate_minutes' => $zeroRateArray,
        'resolution_hash' => str_repeat('c', 64), 'created_at' => now(),
    ];

    $canonical = DB::transaction(fn () => app(AttendanceDecisionAppender::class)->append(
        $parent,
        $current,
        fn (): OvertimeDecision => OvertimeDecision::withoutCompanyScope()->create($attributes),
    ));

    expect($canonical->supersedes_id)->toBe($legacyId)
        ->and($canonical->candidate_key)->toBe($current->key)
        ->and($canonical->fingerprint)->toBe($current->fingerprint);
});

test('rejects wrong and reused V1 overtime supersession authorizations', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $rates = new BandSplit(ordinaryMinutes: 0, extra25Minutes: 30);
    $legacy = new AttendanceSegment(
        'post_shift', CarbonImmutable::parse('2026-07-20 14:00:00'), CarbonImmutable::parse('2026-07-20 14:30:00'),
        str_repeat('d', 64), $rates,
    );
    $current = (new AttendanceSegment(
        'post_quota_overtime', CarbonImmutable::parse('2026-07-20 14:00:00'), CarbonImmutable::parse('2026-07-20 14:30:00'),
        str_repeat('e', 64), $rates,
    ))->withCompatibleIdentities($legacy->identities());
    $rateArray = $current->identity->rateMinutes;
    $zeroRateArray = array_fill_keys(array_keys($rateArray), 0);
    $rateMinutes = json_encode($rateArray, JSON_THROW_ON_ERROR);
    $legacyId = DB::table('overtime_decisions')->insertGetId([
        'record_version' => 1, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'candidate_key' => $legacy->key,
        'fingerprint' => $legacy->fingerprint, 'segment_kind' => $legacy->kind,
        'starts_at' => $legacy->start, 'ends_at' => $legacy->end, 'minutes' => 30,
        'rate_minutes' => $rateMinutes, 'decision' => 'approved', 'reason' => 'Approved legacy fact',
        'decided_by' => $actor->id, 'created_at' => now(),
    ]);
    $parent = OvertimeDecision::withoutCompanyScope()->findOrFail($legacyId);
    $attributes = [
        'record_version' => 2, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'candidate_key' => $current->key,
        'fingerprint' => $current->fingerprint, 'segment_kind' => $current->kind,
        'starts_at' => $current->start, 'ends_at' => $current->end, 'minutes' => 30,
        'rate_minutes' => $rateArray, 'decision' => 'approved', 'reason' => 'Approved canonical fact',
        'decided_by' => $actor->id, 'supersedes_id' => $legacyId, 'resolution_kind' => 'whole_approve',
        'approved_starts_at' => $current->start, 'approved_ends_at' => $current->end,
        'approved_minutes' => 30, 'rejected_minutes' => 0, 'rejected_before_minutes' => 0,
        'rejected_after_minutes' => 0, 'approved_rate_minutes' => $rateArray,
        'rejected_rate_minutes' => $zeroRateArray, 'resolution_hash' => str_repeat('f', 64), 'created_at' => now(),
    ];
    $wrong = overtimeSqlState(fn () => DB::transaction(fn () => app(AttendanceDecisionAppender::class)->append(
        $parent,
        $current,
        fn (): OvertimeDecision => OvertimeDecision::withoutCompanyScope()->create([
            ...$attributes,
            'fingerprint' => str_repeat('0', 64),
        ]),
    )));
    $reused = overtimeSqlState(fn () => DB::transaction(fn () => app(AttendanceDecisionAppender::class)->append(
        $parent,
        $current,
        function () use ($attributes): OvertimeDecision {
            $first = OvertimeDecision::withoutCompanyScope()->create($attributes);
            OvertimeDecision::withoutCompanyScope()->create([...$attributes, 'resolution_hash' => str_repeat('1', 64)]);

            return $first;
        },
    )));

    expect($wrong)->toBe('23514')
        ->and($reused)->toBe('23514')
        ->and(DB::table('overtime_decisions')->count())->toBe(1);
});

test('rejects a compatible V1 overtime supersession without exact authorization', function () {
    $transition = compatibleV1OvertimeTransition();
    $attributes = $transition['attributes'];
    $attributes['rate_minutes'] = json_encode($attributes['rate_minutes'], JSON_THROW_ON_ERROR);

    $state = overtimeSqlState(fn () => DB::table('overtime_decisions')->insert($attributes));

    expect($state)->toBe('23514')
        ->and(DB::table('overtime_decisions')->count())->toBe(1);
});

test('keeps ordinary same-canonical-identity V1 overtime history', function () {
    $transition = compatibleV1OvertimeTransition();
    $parent = $transition['parent'];

    $childId = DB::table('overtime_decisions')->insertGetId([
        'record_version' => 1, 'company_id' => $parent->company_id, 'pay_period_id' => $parent->pay_period_id,
        'employee_id' => $parent->employee_id, 'work_date' => $parent->work_date,
        'candidate_key' => $parent->candidate_key, 'fingerprint' => $parent->fingerprint,
        'segment_kind' => $parent->segment_kind, 'starts_at' => $parent->starts_at, 'ends_at' => $parent->ends_at,
        'minutes' => $parent->minutes,
        'rate_minutes' => json_encode($parent->rate_minutes, JSON_THROW_ON_ERROR),
        'decision' => 'rejected', 'reason' => 'Changed canonical decision',
        'decided_by' => $parent->decided_by, 'supersedes_id' => $parent->id, 'created_at' => now(),
    ]);

    expect(DB::table('overtime_decisions')->where('id', $childId)->value('supersedes_id'))->toBe($parent->id);
});

test('accepts and consumes one exact compatible V1 overtime supersession authorization', function () {
    $transition = compatibleV1OvertimeTransition();

    $current = DB::transaction(function () use ($transition): OvertimeDecision {
        $decision = app(AttendanceDecisionAppender::class)->append(
            $transition['parent'],
            $transition['current'],
            fn (): OvertimeDecision => OvertimeDecision::withoutCompanyScope()->create($transition['attributes']),
        );
        $capability = DB::selectOne(
            "select current_setting('nomina.attendance_compatible_supersession', true) as value",
        );
        expect($capability->value)->toBe('');

        return $decision;
    });

    expect($current->record_version)->toBe(1)
        ->and($current->supersedes_id)->toBe($transition['parent']->id)
        ->and($current->candidate_key)->toBe($transition['current']->key);
});

test('rejects wrong and reused compatible V1 overtime authorizations', function () {
    $wrongTransition = compatibleV1OvertimeTransition();
    $wrong = overtimeSqlState(fn () => DB::transaction(fn () => app(AttendanceDecisionAppender::class)->append(
        $wrongTransition['parent'],
        $wrongTransition['current'],
        fn (): OvertimeDecision => OvertimeDecision::withoutCompanyScope()->create([
            ...$wrongTransition['attributes'],
            'fingerprint' => str_repeat('4', 64),
        ]),
    )));
    $reusedTransition = compatibleV1OvertimeTransition();
    $reused = overtimeSqlState(fn () => DB::transaction(fn () => app(AttendanceDecisionAppender::class)->append(
        $reusedTransition['parent'],
        $reusedTransition['current'],
        function () use ($reusedTransition): OvertimeDecision {
            $first = OvertimeDecision::withoutCompanyScope()->create($reusedTransition['attributes']);
            OvertimeDecision::withoutCompanyScope()->create([
                ...$reusedTransition['attributes'],
                'decision' => 'approved',
                'reason' => 'Attempted capability reuse',
            ]);

            return $first;
        },
    )));

    expect($wrong)->toBe('23514')
        ->and($reused)->toBe('23514')
        ->and(DB::table('overtime_decisions')->count())->toBe(2);
});
