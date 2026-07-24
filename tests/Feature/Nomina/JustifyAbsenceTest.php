<?php

use App\Livewire\Nomina\Revisar;
use App\Models\AttendanceException;
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
use App\Services\Attendance\AttendanceExceptionRecorder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('justifyAbsence grants an append-only full-day attendance exception', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
    ]);
    $employee = absenceEmployeeWithDefaultSchedule($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'permission', 'Personal matters')
        ->assertHasNoErrors();

    $exception = AttendanceException::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->where('pay_period_id', $payPeriod->id)
        ->where('employee_id', $employee->id)
        ->whereDate('work_date', '2026-01-05')
        ->current()
        ->first();

    expect($exception)->not->toBeNull()
        ->and($exception->segment_kind)->toBe('full_day_absence')
        ->and($exception->decision)->toBe(AttendanceException::GRANTED)
        ->and($exception->reason)->toBe('permission: Personal matters')
        ->and($exception->decided_by)->toBe($admin->id)
        ->and($exception->starts_at->format('Y-m-d H:i'))->toBe('2026-01-05 06:00')
        ->and($exception->ends_at->format('Y-m-d H:i'))->toBe('2026-01-05 14:00')
        ->and($exception->minutes)->toBe(480)
        ->and($exception->rate_minutes)->toBe([
            'ordinary' => 480,
            'extra25' => 0,
            'extra50' => 0,
            'extra75' => 0,
            'extra100' => 0,
        ])
        ->and(JustifiedAbsence::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->exists())->toBeFalse();
});

test('justifyAbsence enters the attendance recorder without an outer payroll-period transaction', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
    ]);
    $employee = absenceEmployeeWithDefaultSchedule($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $baselineTransactionLevel = DB::transactionLevel();
    $transactionLevel = null;
    $recorder = Mockery::mock(AttendanceExceptionRecorder::class);
    $recorder->shouldReceive('decide')->once()->andReturnUsing(
        function () use (&$transactionLevel): AttendanceException {
            $transactionLevel = DB::transactionLevel();

            return new AttendanceException;
        },
    );
    app()->instance(AttendanceExceptionRecorder::class, $recorder);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'permission')
        ->assertHasNoErrors();

    expect($transactionLevel)->toBe($baselineTransactionLevel);
});

test('justifyAbsence cannot write after the period becomes processed', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => 'validating',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod]);
    $raceTriggered = false;
    $disarmRace = simulateAbsenceProcessingRace($payPeriod, $raceTriggered);

    $component
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'permission')
        ->assertHasNoErrors();
    $disarmRace();

    expect($raceTriggered)->toBeTrue();
    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and(AttendanceException::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->exists())->toBeFalse();
});

test('justifyAbsence leaves legacy absence data untouched and records a new exception', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
    ]);
    $employee = absenceEmployeeWithDefaultSchedule($company);
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
    $exception = AttendanceException::withoutCompanyScope()
        ->where('pay_period_id', $payPeriod->id)
        ->where('employee_id', $employee->id)
        ->current()
        ->first();

    expect($absence)->not->toBeNull()
        ->and($absence->reason)->toBe('other')
        ->and($absence->notes)->toBe('Original note')
        ->and($exception)->not->toBeNull()
        ->and($exception->decision)->toBe(AttendanceException::GRANTED)
        ->and($exception->reason)->toBe('holiday: Updated note')
        ->and($exception->minutes)->toBe(480);

    expect(JustifiedAbsence::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(1)
        ->and(AttendanceException::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(1);
});

test('a full day justification becomes stale when the assigned schedule changes', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'validating',
    ]);
    $employee = absenceEmployeeWithDefaultSchedule($company);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'permission', 'Turno diurno autorizado')
        ->assertHasNoErrors();

    $initial = app(PayrollShiftEvaluationResolver::class)->resolve($payPeriod, $employee, '2026-01-05');

    expect($initial->isJustified)->toBeTrue()
        ->and($initial->recognizedMinutes)->toBe(480);

    $nightProfile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($nightProfile)->create([
        'day_of_week' => 1,
        'is_working_day' => true,
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    app(EmployeeScheduleAssigner::class)->assign(
        $employee,
        $nightProfile,
        '2026-01-05',
        'Cambio a turno nocturno',
        $admin,
    );

    $stale = app(PayrollShiftEvaluationResolver::class)->resolve($payPeriod, $employee, '2026-01-05');

    expect($stale->isJustified)->toBeFalse()
        ->and($stale->recognizedMinutes)->toBe(0);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'permission', 'Turno nocturno autorizado')
        ->assertHasNoErrors();

    $renewed = app(PayrollShiftEvaluationResolver::class)->resolve($payPeriod, $employee, '2026-01-05');
    $exceptions = AttendanceException::withoutCompanyScope()
        ->where('pay_period_id', $payPeriod->id)
        ->where('employee_id', $employee->id)
        ->orderBy('id')
        ->get();

    expect($renewed->isJustified)->toBeTrue()
        ->and($renewed->recognizedMinutes)->toBe(720)
        ->and($renewed->payableRates->extra50Minutes)->toBe(360)
        ->and($renewed->payableRates->extra75Minutes)->toBe(360)
        ->and($exceptions)->toHaveCount(2)
        ->and($exceptions[0]->fingerprint)->not->toBe($exceptions[1]->fingerprint);
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

    expect(AttendanceException::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
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

    expect(AttendanceException::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
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

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('absenceReason', 'permission')
        ->call('justifyAbsence', $employee->id, '2026-01-05', 'permission')
        ->assertViewHas('faltas', function ($faltas) use ($employee) {
            $mondayFalta = $faltas->first(function ($falta) {
                return $falta['date']->toDateString() === '2026-01-05';
            });

            return $faltas->count() === 6
                && $mondayFalta !== null
                && $mondayFalta['employee']->id === $employee->id
                && $mondayFalta['attendance_exception'] !== null;
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

function simulateAbsenceProcessingRace(PayPeriod $payPeriod, bool &$triggered): Closure
{
    $armed = true;

    DB::connection()->beforeStartingTransaction(function () use (&$armed, &$triggered, $payPeriod): void {
        if (! $armed || $triggered) {
            return;
        }

        $triggered = true;

        DB::table('pay_periods')
            ->where('id', $payPeriod->id)
            ->update(['status' => 'processed']);
    });

    return function () use (&$armed): void {
        $armed = false;
    };
}
