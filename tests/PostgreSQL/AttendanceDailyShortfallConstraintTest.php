<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\User;
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
