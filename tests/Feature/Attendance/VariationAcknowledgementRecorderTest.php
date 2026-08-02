<?php

use App\Models\AttendanceVariationAcknowledgement;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\Attendance\VariationAcknowledgementRecorder;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(PermissionRoleSeeder::class));

test('provides the variation acknowledgement persistence module', function () {
    expect(Schema::hasColumns('attendance_variation_acknowledgements', [
        'record_version', 'variation_key', 'fingerprint', 'variation_kind', 'entry_at', 'acknowledged_by',
    ]))->toBeTrue()
        ->and(new AttendanceVariationAcknowledgement)->toBeInstanceOf(Model::class)
        ->and(app(VariationAcknowledgementRecorder::class))->toBeInstanceOf(VariationAcknowledgementRecorder::class);
});

test('appends the current V2 variation audit without changing pay', function () {
    $context = variationAcknowledgementFixture();
    $before = app(PayrollShiftEvaluationResolver::class)->resolve($context['period'], $context['employee'], '2026-07-20');
    $variation = app(PayrollShiftEvaluationResolver::class)->review($context['period'], $context['employee'], '2026-07-20')->analysis->variations->sole();

    $acknowledgement = app(VariationAcknowledgementRecorder::class)->acknowledge(
        $context['period'], $context['employee'], '2026-07-20', $variation->key,
        $variation->fingerprint, 'Reviewed with employee', $context['actor'],
    );
    $after = app(PayrollShiftEvaluationResolver::class)->resolve($context['period'], $context['employee'], '2026-07-20');

    expect($acknowledgement->record_version)->toBe(2)
        ->and($acknowledgement->acknowledged_by)->toBe($context['actor']->id)
        ->and($acknowledgement->reason)->toBe('Reviewed with employee')
        ->and($acknowledgement->fingerprint)->toBe($variation->fingerprint)
        ->and($acknowledgement->created_at)->not->toBeNull()
        ->and($after->payableRates)->toEqual($before->payableRates);
});

test('requires a nonblank acknowledgement reason', function () {
    $context = variationAcknowledgementFixture();
    $variation = currentVariation($context);

    expect(fn () => app(VariationAcknowledgementRecorder::class)->acknowledge(
        $context['period'], $context['employee'], '2026-07-20', $variation->key, $variation->fingerprint, '  ', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(AttendanceVariationAcknowledgement::count())->toBe(0);
});

test('rejects a stale variation fingerprint without appending', function () {
    $context = variationAcknowledgementFixture();
    $variation = currentVariation($context);

    expect(fn () => app(VariationAcknowledgementRecorder::class)->acknowledge(
        $context['period'], $context['employee'], '2026-07-20', $variation->key, str_repeat('a', 64), 'Reviewed', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(AttendanceVariationAcknowledgement::count())->toBe(0);
});

test('requires an authorized active actor for acknowledgement', function () {
    $context = variationAcknowledgementFixture();
    $variation = currentVariation($context);
    $unauthorized = User::factory()->forCompany($context['company'])->create();
    $record = fn (User $actor) => app(VariationAcknowledgementRecorder::class)->acknowledge(
        $context['period'], $context['employee'], '2026-07-20', $variation->key, $variation->fingerprint, 'Reviewed', $actor,
    );

    expect(fn () => $record($unauthorized))->toThrow(AuthorizationException::class)
        ->and($record($context['actor'])->acknowledged_by)->toBe($context['actor']->id);
});

test('rejects an inactive authorized actor without appending', function () {
    $context = variationAcknowledgementFixture();
    $variation = currentVariation($context);
    $inactive = User::factory()->forCompany($context['company'])->create(['is_active' => false])->assignRole('company_admin');

    expect(fn () => app(VariationAcknowledgementRecorder::class)->acknowledge(
        $context['period'], $context['employee'], '2026-07-20', $variation->key, $variation->fingerprint, 'Reviewed', $inactive,
    ))->toThrow(AuthorizationException::class)
        ->and(AttendanceVariationAcknowledgement::count())->toBe(0);
});

test('isolates acknowledgements to the actors active tenant', function () {
    $context = variationAcknowledgementFixture();
    $variation = currentVariation($context);
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');
    app(CurrentCompany::class)->set(Company::factory()->create());

    expect(fn () => app(VariationAcknowledgementRecorder::class)->acknowledge(
        $context['period'], $context['employee'], '2026-07-20', $variation->key, $variation->fingerprint, 'Reviewed', $superAdmin,
    ))->toThrow(AuthorizationException::class)
        ->and(AttendanceVariationAcknowledgement::count())->toBe(0);
});

function variationAcknowledgementFixture(string $status = 'uploaded'): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    WorkSchedule::factory()->forProfile($profile)->create(['day_of_week' => 1, 'start_time' => '06:00', 'end_time' => '14:00']);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'General schedule');
    $period = PayPeriod::factory()->forCompany($company)->create(['start_date' => '2026-07-20', 'end_date' => '2026-07-20', 'status' => $status]);
    foreach (['2026-07-20 07:00:00', '2026-07-20 15:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']);
    }
    DB::table('work_schedule_profile_publications')->where('profile_id', $profile->id)->update([
        'payroll_policy_key' => 'duration-first-v2', 'published_by' => $actor->id,
    ]);
    app(CurrentCompany::class)->set($company);

    return compact('company', 'actor', 'employee', 'period');
}

function currentVariation(array $context)
{
    return app(PayrollShiftEvaluationResolver::class)
        ->review($context['period'], $context['employee'], '2026-07-20')->analysis->variations->sole();
}
