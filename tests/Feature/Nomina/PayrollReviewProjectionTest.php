<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\PayPeriod;
use App\Models\PayrollReviewEntry;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayrollReviewProjection;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('payroll review rebuild command is idempotent', function () {
    $context = payrollReviewProjectionFixture();

    Artisan::call('payroll:review:rebuild', ['pay_period_id' => $context['period']->id]);
    Artisan::call('payroll:review:rebuild', ['pay_period_id' => $context['period']->id]);

    expect(PayrollReviewEntry::query()->where('pay_period_id', $context['period']->id)->count())->toBe(1)
        ->and(PayrollReviewEntry::query()->sole()->type)->toBe('overtime_candidate');
});

test('payroll review screen reads fresh projected overtime rows', function () {
    $context = payrollReviewProjectionFixture();
    Artisan::call('payroll:review:rebuild', ['pay_period_id' => $context['period']->id]);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']->fresh()])
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertViewHas('overtimeGroups', fn ($groups) => $groups->count() === 1)
        ->assertSee('María Guardia')
        ->assertSee('Salida posterior')
        ->assertSee('30 min · 0,50 h');
});

test('payroll review screen falls back to legacy calculation when projection is unavailable', function () {
    $context = payrollReviewProjectionFixture();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertSee('Salida posterior');
});

test('payroll review projection generation includes assignment publication and holiday context', function () {
    $context = payrollReviewProjectionFixture();
    $projection = app(PayrollReviewProjection::class);
    $baseline = $projection->generation($context['period']);

    $assignment = $context['employee']->scheduleAssignments()->sole();
    $assignment->update(['reason' => 'Updated jornada assignment']);
    $afterAssignment = $projection->generation($context['period']);
    $publication = $context['profile']->publications()->sole();
    $publication->update(['payroll_policy_key' => 'duration-first-v2']);
    $afterPublication = $projection->generation($context['period']);
    Holiday::factory()->forCompany($context['company'])->create(['date' => '2026-07-20']);

    expect($projection->generation($context['period']))->not->toBe($afterPublication)
        ->and($afterPublication)->not->toBe($afterAssignment)
        ->and($afterAssignment)->not->toBe($baseline)
        ->and($projection->generation($context['period']))->toBe($projection->generation($context['period']));
});

function payrollReviewProjectionFixture(): array
{
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'base_ordinary_hours' => 8,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'first_name' => 'María',
        'last_name' => 'Guardia',
        'external_id' => 'SEG-101',
    ]);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada diurna');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-20',
        'status' => 'uploaded',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    foreach (['2026-07-20 06:00:00', '2026-07-20 14:30:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);

    return compact('company', 'employee', 'profile', 'period', 'actor');
}
