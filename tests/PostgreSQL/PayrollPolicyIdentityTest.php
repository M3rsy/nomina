<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Attendance\ShiftOccurrenceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PAYROLL_POLICY_IDENTITY_MIGRATION = 'database/migrations/2026_07_30_000001_create_work_schedule_profile_publications.php';

function payrollPolicySqlState(Closure $operation): ?string
{
    try {
        $operation();
    } catch (QueryException $exception) {
        return $exception->getCode();
    }

    return null;
}

function remigratePayrollPolicyIdentity(): void
{
    Artisan::call('migrate:rollback', ['--path' => PAYROLL_POLICY_IDENTITY_MIGRATION, '--force' => true]);
    Artisan::call('migrate', ['--path' => PAYROLL_POLICY_IDENTITY_MIGRATION, '--force' => true]);
}

test('backfills future legacy coverage for an unassigned pre-existing active profile', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '08:00',
        'end_time' => '16:00',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();

    $this->travelTo(CarbonImmutable::parse('2026-07-30 12:00:00'));
    remigratePayrollPolicyIdentity();
    $this->travelBack();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-08-03', 'Future legacy assignment');

    $applicablePublications = DB::table('work_schedule_profile_publications')
        ->where('company_id', $company->id)
        ->where('profile_id', $profile->id)
        ->whereDate('effective_from', '<=', '2026-08-03')
        ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>', '2026-08-03'))
        ->get();
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-08-03');

    expect($applicablePublications)->toHaveCount(1)
        ->and($applicablePublications->sole()->payroll_policy_key)->toBe('schedule-overlap-v1')
        ->and($occurrence->status)->toBe(ShiftOccurrence::NO_MARKS)
        ->and($occurrence->publicationId)->toBe($applicablePublications->sole()->id)
        ->and($occurrence->payrollPolicyKey)->toBe('schedule-overlap-v1');
});

test('backfills inclusive shared profile coverage as one canonical half open range', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $employees = Employee::factory()->count(3)->forCompany($company)->create();
    DB::table('employee_schedule_assignments')->insert([
        ['company_id' => $company->id, 'employee_id' => $employees[0]->id, 'work_schedule_profile_id' => $profile->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-10', 'reason' => 'Legacy A', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $company->id, 'employee_id' => $employees[1]->id, 'work_schedule_profile_id' => $profile->id, 'effective_from' => '2026-01-11', 'effective_to' => '2026-01-20', 'reason' => 'Legacy B', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $company->id, 'employee_id' => $employees[2]->id, 'work_schedule_profile_id' => $profile->id, 'effective_from' => '2026-01-05', 'effective_to' => '2026-01-15', 'reason' => 'Legacy C', 'created_at' => now(), 'updated_at' => now()],
    ]);

    remigratePayrollPolicyIdentity();

    $publication = DB::table('work_schedule_profile_publications')
        ->where('effective_from', '2026-01-01')->sole();
    expect($publication->payroll_policy_key)->toBe('schedule-overlap-v1')
        ->and($publication->effective_from)->toBe('2026-01-01')
        ->and($publication->effective_to)->toBe('2026-01-21')
        ->and(DB::table('work_schedule_profile_publications')
            ->whereDate('effective_from', '<=', '2026-01-20')
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>', '2026-01-20'))
            ->count())->toBe(1);
});

test('enforces immutable constrained publication identity with PostgreSQL states', function () {
    $company = Company::factory()->create();
    $profiles = WorkScheduleProfile::factory()->count(4)->forCompany($company)->create();
    $publications = DB::table('work_schedule_profile_publications')
        ->whereIn('profile_id', $profiles->modelKeys())->orderBy('id')->get();
    $attributes = fn (object $publication, array $overrides = []): array => array_replace([
        'company_id' => $publication->company_id, 'profile_key' => $publication->profile_key,
        'profile_id' => $publication->profile_id, 'payroll_policy_key' => $publication->payroll_policy_key,
        'effective_from' => $publication->effective_from, 'effective_to' => $publication->effective_to,
        'definition_hash' => $publication->definition_hash, 'request_key' => $publication->request_key,
        'payload_hash' => $publication->payload_hash, 'reason' => $publication->reason,
        'published_by' => null, 'created_at' => now(), 'updated_at' => now(),
    ], $overrides);

    $duplicate = payrollPolicySqlState(fn () => DB::table('work_schedule_profile_publications')->insert(
        $attributes($publications[0], ['payload_hash' => str_repeat('a', 64)]),
    ));
    $overlap = payrollPolicySqlState(fn () => DB::table('work_schedule_profile_publications')->insert(
        $attributes($publications[1], ['request_key' => str_repeat('b', 64), 'payload_hash' => str_repeat('c', 64)]),
    ));
    $immutable = payrollPolicySqlState(fn () => DB::table('work_schedule_profile_publications')
        ->where('id', $publications[2]->id)->update(['reason' => 'Mutated']));
    $deleted = payrollPolicySqlState(fn () => DB::table('work_schedule_profile_publications')
        ->where('id', $publications[3]->id)->delete());
    DB::table('work_schedule_profile_publications')->where('id', $publications[0]->id)
        ->update(['effective_to' => '2026-01-01']);
    $invalid = payrollPolicySqlState(fn () => DB::table('work_schedule_profile_publications')->insert(
        $attributes($publications[0], ['payroll_policy_key' => 'duration-first-v2', 'effective_from' => '2026-01-01', 'request_key' => str_repeat('d', 64)]),
    ));
    $otherProfile = WorkScheduleProfile::factory()->forCompany(Company::factory()->create())->create();
    $other = DB::table('work_schedule_profile_publications')->where('profile_id', $otherProfile->id)->sole();
    $foreign = payrollPolicySqlState(fn () => DB::table('work_schedule_profile_publications')->insert(
        $attributes($other, ['company_id' => $company->id, 'request_key' => str_repeat('e', 64)]),
    ));

    expect($duplicate)->toBe('23505')
        ->and($overlap)->toBe('23P01')
        ->and($immutable)->toBe('23514')
        ->and($deleted)->toBe('23514')
        ->and($invalid)->toBe('23514')
        ->and($foreign)->toBe('23503');
});

test('rejects invalid legacy assignment history before writing publication schema', function () {
    $company = Company::factory()->create();
    $foreignProfile = WorkScheduleProfile::factory()->forCompany(Company::factory()->create())->create();
    $employee = Employee::factory()->forCompany($company)->create();
    DB::table('employee_schedule_assignments')->insert([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'work_schedule_profile_id' => $foreignProfile->id, 'effective_from' => '2026-01-01',
        'effective_to' => null, 'reason' => 'Invalid tenant history',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Artisan::call('migrate:rollback', ['--path' => PAYROLL_POLICY_IDENTITY_MIGRATION, '--force' => true]);

    $exception = null;
    try {
        Artisan::call('migrate', ['--path' => PAYROLL_POLICY_IDENTITY_MIGRATION, '--force' => true]);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception?->getMessage())->toBe('Cannot publish legacy payroll policy identity: invalid assignment history.')
        ->and(Schema::hasTable('work_schedule_profile_publications'))->toBeFalse();
});
