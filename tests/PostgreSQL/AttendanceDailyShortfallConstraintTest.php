<?php

use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\Attendance\AttendanceDecisionAppender;
use App\Services\Attendance\AttendanceSegment;
use App\Services\Payroll\BandSplit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function dailyShortfallSqlState(Closure $operation): ?string
{
    try {
        $operation();
    } catch (QueryException $exception) {
        return $exception->getCode();
    }

    return null;
}

/** @return array{parent:AttendanceException,current:AttendanceSegment,attributes:array<string,mixed>} */
function compatibleV1AttendanceTransition(): array
{
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $rates = new BandSplit(ordinaryMinutes: 30);
    $released = new AttendanceSegment(
        'late_arrival', CarbonImmutable::parse('2026-07-20 06:00:00'), CarbonImmutable::parse('2026-07-20 06:30:00'),
        str_repeat('2', 64), $rates,
    );
    $current = (new AttendanceSegment(
        'late_arrival', CarbonImmutable::parse('2026-07-20 06:00:00'), CarbonImmutable::parse('2026-07-20 06:30:00'),
        str_repeat('3', 64), $rates,
    ))->withCompatibleIdentities($released->identities());
    $parentId = DB::table('attendance_exceptions')->insertGetId([
        'record_version' => 1, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'deficit_key' => $released->key,
        'fingerprint' => $released->fingerprint, 'segment_kind' => $released->kind,
        'starts_at' => $released->start, 'ends_at' => $released->end, 'minutes' => 30,
        'rate_minutes' => json_encode($released->identity->rateMinutes, JSON_THROW_ON_ERROR),
        'decision' => 'granted', 'reason' => 'Granted released identity', 'decided_by' => $actor->id,
        'created_at' => now(),
    ]);

    return [
        'parent' => AttendanceException::withoutCompanyScope()->findOrFail($parentId),
        'current' => $current,
        'attributes' => [
            'record_version' => 1, 'company_id' => $company->id, 'pay_period_id' => $period->id,
            'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'deficit_key' => $current->key,
            'fingerprint' => $current->fingerprint, 'segment_kind' => $current->kind,
            'starts_at' => $current->start, 'ends_at' => $current->end, 'minutes' => 30,
            'rate_minutes' => $current->identity->rateMinutes, 'decision' => 'revoked',
            'reason' => 'Revoked canonical identity', 'decided_by' => $actor->id,
            'supersedes_id' => $parentId, 'created_at' => now(),
        ],
    ];
}

test('enforces immutable whole daily shortfall decisions with PostgreSQL state 23514', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $rates = json_encode([
        'ordinary' => 60,
        'extra25' => 0,
        'extra50' => 0,
        'extra75' => 0,
        'extra100' => 0,
    ], JSON_THROW_ON_ERROR);
    $attributes = fn (array $overrides = []): array => array_replace([
        'record_version' => 2,
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-07-20',
        'deficit_key' => str_repeat('a', 64),
        'fingerprint' => str_repeat('b', 64),
        'segment_kind' => 'daily_shortfall',
        'starts_at' => null,
        'ends_at' => null,
        'minutes' => 60,
        'rate_minutes' => $rates,
        'decision' => 'granted',
        'reason' => 'Authorized complete shortfall',
        'decided_by' => $actor->id,
        'supersedes_id' => null,
        'created_at' => now(),
    ], $overrides);

    $interval = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')->insert(
        $attributes(['starts_at' => '2026-07-20 07:00:00']),
    ));
    $wrongRates = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')->insert(
        $attributes(['rate_minutes' => json_encode(array_replace(json_decode($rates, true), ['ordinary' => 59]), JSON_THROW_ON_ERROR)]),
    ));
    $orphanRevocation = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')->insert(
        $attributes(['decision' => 'revoked']),
    ));
    $grantId = DB::table('attendance_exceptions')->insertGetId($attributes());
    $mismatchedRevocation = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')->insert(
        $attributes([
            'decision' => 'revoked',
            'fingerprint' => str_repeat('c', 64),
            'supersedes_id' => $grantId,
        ]),
    ));
    $revocationId = DB::table('attendance_exceptions')->insertGetId($attributes([
        'decision' => 'revoked',
        'reason' => 'Authorization revoked',
        'supersedes_id' => $grantId,
    ]));
    DB::table('attendance_exceptions')->insert($attributes([
        'decision' => 'rejected',
        'reason' => 'Shortfall remains unpaid',
        'supersedes_id' => $revocationId,
    ]));
    $duplicateBranch = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')->insert(
        $attributes(['supersedes_id' => $revocationId]),
    ));
    $mutation = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')
        ->where('id', $revocationId)->update(['reason' => 'Changed']));
    $deletion = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')
        ->where('id', $revocationId)->delete());

    expect($interval)->toBe('23514')
        ->and($wrongRates)->toBe('23514')
        ->and($orphanRevocation)->toBe('23514')
        ->and($mismatchedRevocation)->toBe('23514')
        ->and($duplicateBranch)->toBe('23514')
        ->and($mutation)->toBe('23514')
        ->and($deletion)->toBe('23514')
        ->and(DB::table('attendance_exceptions')->orderBy('id')->pluck('decision')->all())
        ->toBe(['granted', 'revoked', 'rejected']);
});

test('rejects a shape-only V2 daily shortfall supersession without verified predecessor authorization', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $rates = json_encode([
        'ordinary' => 30,
        'extra25' => 0,
        'extra50' => 0,
        'extra75' => 0,
        'extra100' => 0,
    ], JSON_THROW_ON_ERROR);
    $context = [
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-07-20',
        'minutes' => 30,
        'rate_minutes' => $rates,
        'reason' => 'Authorized exact shortfall',
        'decided_by' => $actor->id,
        'created_at' => now(),
    ];
    $legacyId = DB::table('attendance_exceptions')->insertGetId(array_replace($context, [
        'record_version' => 1,
        'deficit_key' => str_repeat('a', 64),
        'fingerprint' => str_repeat('b', 64),
        'segment_kind' => 'late_arrival',
        'starts_at' => '2026-07-20 06:00:00',
        'ends_at' => '2026-07-20 06:30:00',
        'decision' => 'granted',
    ]));

    $state = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')->insert(array_replace($context, [
        'record_version' => 2,
        'deficit_key' => str_repeat('c', 64),
        'fingerprint' => str_repeat('d', 64),
        'segment_kind' => 'daily_shortfall',
        'starts_at' => null,
        'ends_at' => null,
        'decision' => 'revoked',
        'supersedes_id' => $legacyId,
    ])));

    expect($state)->toBe('23514')
        ->and(DB::table('attendance_exceptions')->count())->toBe(1);
});

test('accepts one exact matcher-compatible V1 daily shortfall supersession authorization', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $rates = new BandSplit(ordinaryMinutes: 30);
    $legacy = new AttendanceSegment(
        'late_arrival',
        CarbonImmutable::parse('2026-07-20 06:00:00'),
        CarbonImmutable::parse('2026-07-20 06:30:00'),
        str_repeat('a', 64),
        $rates,
    );
    $current = (new AttendanceSegment(
        'daily_shortfall', null, null, str_repeat('b', 64), $rates, minutes: 30,
    ))->withCompatibleIdentities($legacy->identities());
    $rateMinutes = json_encode($current->identity->rateMinutes, JSON_THROW_ON_ERROR);
    $legacyId = DB::table('attendance_exceptions')->insertGetId([
        'record_version' => 1, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'deficit_key' => $legacy->key,
        'fingerprint' => $legacy->fingerprint, 'segment_kind' => $legacy->kind,
        'starts_at' => $legacy->start, 'ends_at' => $legacy->end, 'minutes' => 30,
        'rate_minutes' => $rateMinutes, 'decision' => 'granted', 'reason' => 'Granted legacy fact',
        'decided_by' => $actor->id, 'created_at' => now(),
    ]);
    $parent = AttendanceException::withoutCompanyScope()->findOrFail($legacyId);
    $attributes = [
        'record_version' => 2, 'company_id' => $company->id, 'pay_period_id' => $period->id,
        'employee_id' => $employee->id, 'work_date' => '2026-07-20', 'deficit_key' => $current->key,
        'fingerprint' => $current->fingerprint, 'segment_kind' => $current->kind,
        'starts_at' => null, 'ends_at' => null, 'minutes' => 30, 'rate_minutes' => $current->identity->rateMinutes,
        'decision' => 'revoked', 'reason' => 'Revoked canonical fact', 'decided_by' => $actor->id,
        'supersedes_id' => $legacyId, 'created_at' => now(),
    ];

    $canonical = DB::transaction(fn () => app(AttendanceDecisionAppender::class)->append(
        $parent,
        $current,
        fn (): AttendanceException => AttendanceException::withoutCompanyScope()->create($attributes),
    ));

    expect($canonical->supersedes_id)->toBe($legacyId)
        ->and($canonical->deficit_key)->toBe($current->key)
        ->and($canonical->fingerprint)->toBe($current->fingerprint);
});

test('rejects a compatible V1 attendance supersession without exact authorization', function () {
    $transition = compatibleV1AttendanceTransition();
    $attributes = $transition['attributes'];
    $attributes['rate_minutes'] = json_encode($attributes['rate_minutes'], JSON_THROW_ON_ERROR);

    $state = dailyShortfallSqlState(fn () => DB::table('attendance_exceptions')->insert($attributes));

    expect($state)->toBe('23514')
        ->and(DB::table('attendance_exceptions')->count())->toBe(1);
});

test('accepts and consumes one exact compatible V1 attendance supersession authorization', function () {
    $transition = compatibleV1AttendanceTransition();

    $current = DB::transaction(function () use ($transition): AttendanceException {
        $exception = app(AttendanceDecisionAppender::class)->append(
            $transition['parent'],
            $transition['current'],
            fn (): AttendanceException => AttendanceException::withoutCompanyScope()->create($transition['attributes']),
        );
        $capability = DB::selectOne(
            "select current_setting('nomina.attendance_compatible_supersession', true) as value",
        );
        expect($capability->value)->toBe('');

        return $exception;
    });

    expect($current->record_version)->toBe(1)
        ->and($current->supersedes_id)->toBe($transition['parent']->id)
        ->and($current->deficit_key)->toBe($transition['current']->key);
});
