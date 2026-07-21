<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\JustifiedAbsence;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('justifyAbsence creates a justified absence with reason and notes', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'permission', 'Personal matters')
        ->assertHasNoErrors();

    $absence = JustifiedAbsence::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->where('pay_period_id', $payPeriod->id)
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-01-05')
        ->first();

    expect($absence)->not->toBeNull()
        ->and($absence->reason)->toBe('permission')
        ->and($absence->notes)->toBe('Personal matters')
        ->and($absence->justified_by)->toBe($admin->id);
});

test('justifyAbsence upserts existing absence for same employee and date', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    JustifiedAbsence::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
        'reason' => 'other',
        'notes' => 'Original note',
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'holiday')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'holiday', 'Updated note')
        ->assertHasNoErrors();

    $absence = JustifiedAbsence::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->where('pay_period_id', $payPeriod->id)
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-01-05')
        ->first();

    expect($absence)->not->toBeNull()
        ->and($absence->reason)->toBe('holiday')
        ->and($absence->notes)->toBe('Updated note')
        ->and($absence->justified_by)->toBe($admin->id);

    expect(JustifiedAbsence::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(1);
});

test('justifyAbsence validates reason against allowed values', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'invalid_reason')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'invalid_reason')
        ->assertHasErrors('absenceReason');

    expect(JustifiedAbsence::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
});

test('justifyAbsence rejects employee from another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($companyA)->create();
    $employeeB = Employee::factory()->forCompany($companyB)->create();
    $adminA = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    $this->actingAs($adminA);
    app(CurrentCompany::class)->set($companyA);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employeeB->id, '2026-01-05', 'permission')
        ->assertHasNoErrors();

    expect(JustifiedAbsence::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
});

test('detectFaltas lists working day without marks as falta', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
    ]);
    absenceEmployeeWithDefaultSchedule($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('faltas', function ($faltas) {
            return $faltas->count() === 6;
        });
});

test('detectFaltas follows each employee assigned schedule', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
    ]);
    $workingProfile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $restProfile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($workingProfile)->create([
        'day_of_week' => 1,
        'is_working_day' => true,
        'start_time' => '06:00',
        'end_time' => '14:00',
    ]);
    WorkSchedule::factory()->forProfile($restProfile)->create([
        'day_of_week' => 1,
        'is_working_day' => false,
        'start_time' => null,
        'end_time' => null,
        'base_ordinary_hours' => 0,
    ]);
    $workingEmployee = Employee::factory()->forCompany($company)->create();
    $restingEmployee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($workingEmployee, $workingProfile, '2020-01-01', 'Turno diurno');
    app(EmployeeScheduleAssigner::class)->assign($restingEmployee, $restProfile, '2020-01-01', 'Día libre');
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('faltas', function ($faltas) use ($workingEmployee) {
            return $faltas->count() === 1
                && $faltas->sole()['employee']->is($workingEmployee);
        });
});

test('detectFaltas pairs justified absence with falta', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
    ]);
    $employee = absenceEmployeeWithDefaultSchedule($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    JustifiedAbsence::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-01-05',
        'reason' => 'permission',
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('faltas', function ($faltas) use ($employee) {
            $mondayFalta = $faltas->first(function ($falta) {
                return $falta['date']->toDateString() === '2026-01-05';
            });

            return $faltas->count() === 6
                && $mondayFalta !== null
                && $mondayFalta['employee']->id === $employee->id
                && $mondayFalta['justified_absence'] !== null;
        });
});

test('detectFaltas excludes non working day Sunday', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
    ]);
    absenceEmployeeWithDefaultSchedule($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('faltas', function ($faltas) {
            $sundayFalta = $faltas->first(function ($falta) {
                return $falta['date']->toDateString() === '2026-01-11';
            });

            return $faltas->count() === 6 && $sundayFalta === null;
        });
});

test('detectFaltas excludes holiday from faltas', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
    ]);
    absenceEmployeeWithDefaultSchedule($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    Holiday::factory()->forCompany($company)->create([
        'date' => '2026-01-06',
        'is_active' => true,
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('faltas', function ($faltas) {
            $tuesdayFalta = $faltas->first(function ($falta) {
                return $falta['date']->toDateString() === '2026-01-06';
            });

            return $faltas->count() === 5 && $tuesdayFalta === null;
        });
});

test('detectFaltas skips duplicate and deleted raw marks when checking marks', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = absenceEmployeeWithDefaultSchedule($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->forEmployee($employee)->create([
        'status' => 'duplicate',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->forEmployee($employee)->create([
        'status' => 'deleted',
        'event_at' => Carbon::parse('2026-01-05 09:00:00'),
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('faltas', function ($faltas) {
            $mondayFalta = $faltas->first(function ($falta) {
                return $falta['date']->toDateString() === '2026-01-05';
            });

            return $faltas->count() === 6 && $mondayFalta !== null;
        });
});

function absenceEmployeeWithDefaultSchedule(Company $company): Employee
{
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();

    foreach ($company->defaultWorkSchedules() as $day => $schedule) {
        WorkSchedule::factory()->forProfile($profile)->create($schedule + ['day_of_week' => $day]);
    }

    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2020-01-01', 'Jornada general');

    return $employee;
}
