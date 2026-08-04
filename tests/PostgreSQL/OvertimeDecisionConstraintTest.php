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
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\ShiftOccurrenceResolver;
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
