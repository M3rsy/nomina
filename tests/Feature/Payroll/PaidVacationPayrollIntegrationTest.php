<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use App\Models\VacationDay;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\PayrollPeriodSnapshotData;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayrollProcessor;
use App\Services\Payroll\PayrollReportingRowAdapter;
use App\Services\Vacations\VacationManager;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Database\Eloquent\Collection;

test('an approved vacation is persisted as a paid non-absence payroll result', function () {
    $this->seed(PermissionRoleSeeder::class);
    $context = paidVacationPayrollContext();
    $vacation = app(VacationManager::class)->approve(
        $context['company'],
        $context['employee'],
        '2026-09-10',
        '2026-09-10',
        'Descanso anual',
        $context['actor'],
    );
    $period = PayPeriod::factory()->forCompany($context['company'])->create([
        'start_date' => '2026-09-10',
        'end_date' => '2026-09-10',
        'status' => 'ready',
    ]);

    $snapshot = PayrollPeriodSnapshotData::capture(
        $period,
        new Collection([$context['employee']]),
    );
    expect(VacationDay::withoutCompanyScope()->active()->count())->toBe(1)
        ->and($snapshot->vacationDay($context['employee'], CarbonImmutable::parse('2026-09-10')))->not->toBeNull();

    app(PayrollProcessor::class)->processPayPeriod($period);

    $result = PayrollResult::withoutCompanyScope()->sole();
    $day = $vacation->days->sole();

    expect($result->day_type)->toBe('paid_vacation')
        ->and($result->vacation_id)->toBe($vacation->id)
        ->and($result->vacation_day_id)->toBe($day->id)
        ->and($result->worked_minutes)->toBe(0)
        ->and($result->scheduled_minutes)->toBe($day->planned_minutes)
        ->and($result->recognized_minutes)->toBe($day->planned_minutes)
        ->and($result->ordinary_minutes + $result->extra_25_minutes + $result->extra_50_minutes
            + $result->extra_75_minutes + $result->extra_100_minutes)->toBe($day->planned_minutes)
        ->and($result->is_absence)->toBeFalse()
        ->and($result->unjustified)->toBeFalse()
        ->and($result->day_snapshot['schema_version'])->toBe(4)
        ->and($result->day_snapshot['publication']['payroll_policy_definition_hash'])
        ->toBe('0d24692c1022ff3cea6457170641c9ce00a3c7b57df9ced8df2d1c352c818488')
        ->and($result->day_snapshot['day_type'])->toBe('paid_vacation')
        ->and($result->day_snapshot['vacation']['id'])->toBe($vacation->id)
        ->and($result->day_snapshot['vacation']['day_id'])->toBe($day->id)
        ->and($result->day_snapshot['vacation']['planned_minutes'])->toBe(480)
        ->and($result->notes)->toContain('Vacación pagada');
});

test('reporting adapter reads schema v4 snapshots as current rows', function () {
    $result = (new PayrollResult)->forceFill([
        'day_snapshot' => [
            'schema_version' => 4,
            'work_date' => '2026-09-11',
            'employee' => ['external_id' => 'E-11', 'name' => 'Luis Pérez'],
            'publication' => [
                'payroll_policy_key' => 'schedule-overlap-v1',
                'payroll_policy_definition_hash' => str_repeat('a', 64),
            ],
            'attendance' => ['marks' => [], 'worked_minutes' => 480],
            'payable_minutes' => ['ordinary' => 480],
        ],
    ]);

    $row = (new PayrollReportingRowAdapter)->adapt($result);

    expect($row['status'])->toBe('CURRENT')
        ->and($row['employee_external_id'])->toBe('E-11')
        ->and($row['worked_minutes'])->toBe(480)
        ->and($row['ordinary_minutes'])->toBe(480);
});

/** @return array{company: Company, actor: User, employee: Employee} */
function paidVacationPayrollContext(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->for($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create(['hired_at' => '2026-01-01']);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create([
        'profile_key' => 'vacation-payroll',
        'name' => 'Jornada para vacaciones',
        'version' => 1,
    ]);

    foreach (Company::defaultWorkSchedules() as $attributes) {
        WorkSchedule::factory()->forProfile($profile)->create($attributes);
    }

    EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_profile_id' => $profile->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'assigned_by' => $actor->id,
    ]);
    app(CurrentCompany::class)->set($company);

    return compact('company', 'actor', 'employee');
}
