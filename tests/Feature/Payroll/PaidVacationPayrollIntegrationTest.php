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
use App\Services\Payroll\PayrollExcelExporter;
use App\Services\Payroll\PayrollProcessor;
use App\Services\Vacations\VacationManager;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        ->and($result->day_snapshot['schema_version'])->toBe(3)
        ->and($result->day_snapshot['day_type'])->toBe('paid_vacation')
        ->and($result->day_snapshot['vacation']['day_id'])->toBe($day->id)
        ->and($result->notes)->toContain('Vacación pagada');

    $path = app(PayrollExcelExporter::class)->export($period->fresh());
    $audit = IOFactory::load($path)->getSheetByName('Auditoría');

    expect($audit)->not->toBeNull()
        ->and($audit->getCell('AA6')->getValue())->toBe('paid_vacation')
        ->and($audit->getCell('AB6')->getValue())->toBe($vacation->id)
        ->and($audit->getCell('AC6')->getValue())->toContain('480 min pagados');

    unlink($path);
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
