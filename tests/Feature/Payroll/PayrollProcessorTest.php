<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceExceptionRecorder;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\PayrollReadinessChecker;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayrollExcelExporter;
use App\Services\Payroll\PayrollProcessingBlocked;
use App\Services\Payroll\PayrollProcessor;
use Database\Seeders\PermissionRoleSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function readyPayPeriod(?Company $company = null, string $start = '2026-01-05', string $end = '2026-01-11'): PayPeriod
{
    $company ??= Company::factory()->create();

    return PayPeriod::factory()->forCompany($company)->create([
        'start_date' => $start,
        'end_date' => $end,
        'status' => 'ready',
    ]);
}

function processorEmployee(Company $company): Employee
{
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();

    foreach ($company->defaultWorkSchedules() as $day => $schedule) {
        WorkSchedule::factory()->forProfile($profile)->create($schedule + ['day_of_week' => $day]);
    }

    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2020-01-01', 'Jornada para nómina');

    return $employee;
}

test('processor rolls back when an overtime candidate is pending', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $employee = processorEmployee($company);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    foreach (['2026-01-05 06:00:00', '2026-01-05 14:30:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    app(CurrentCompany::class)->set($company);

    expect(fn () => app(PayrollProcessor::class)->processPayPeriod($payPeriod))
        ->toThrow(PayrollProcessingBlocked::class)
        ->and($payPeriod->fresh()->status)->toBe('ready')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
});

test('reviewed overtime flows from readiness to exact payroll and export', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $employee = processorEmployee($company);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    foreach (['2026-01-05 06:00:30', '2026-01-05 14:30:30'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    app(CurrentCompany::class)->set($company);
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-01-05');
    $candidate = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $pending = app(PayrollReadinessChecker::class)->blockers($payPeriod)->sole();
    app(OvertimeDecisionRecorder::class)->decide(
        $payPeriod,
        $employee,
        '2026-01-05',
        $candidate->key,
        'approved',
        'Cobertura extraordinaria confirmada',
        $actor,
    );

    expect($pending)->toMatchArray([
        'employee_id' => $employee->id,
        'work_date' => '2026-01-05',
        'code' => 'pending_overtime_candidate',
        'candidate_key' => $candidate->key,
    ])->and(app(PayrollReadinessChecker::class)->blockers($payPeriod))->toBeEmpty();

    app(PayrollProcessor::class)->processPayPeriod($payPeriod);
    $result = PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->sole();
    $path = app(PayrollExcelExporter::class)->export($payPeriod->fresh());
    $data = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

    expect($result->entry_at->toDateTimeString())->toBe('2026-01-05 06:00:30')
        ->and($result->exit_at->toDateTimeString())->toBe('2026-01-05 14:30:30')
        ->and($result->worked_minutes)->toBe(510)
        ->and($result->scheduled_minutes)->toBe(480)
        ->and($result->recognized_minutes)->toBe(510)
        ->and($result->detected_overtime_minutes)->toBe(30)
        ->and($result->approved_overtime_minutes)->toBe(30)
        ->and($result->ordinary_minutes)->toBe(480)
        ->and($result->extra_25_minutes)->toBe(30)
        ->and((float) $result->extra_25_hours)->toBe(0.5)
        ->and($data[5][6])->toBe(0.5)
        ->and($payPeriod->fresh()->status)->toBe('processed');

    unlink($path);
});

test('processor credits an exact granted attendance deficit without changing observed time', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $employee = processorEmployee($company);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    foreach (['2026-01-05 06:15:00', '2026-01-05 14:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    app(CurrentCompany::class)->set($company);
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-01-05');
    $deficit = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->deficits->sole();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $exception = app(AttendanceExceptionRecorder::class)->decide(
        $payPeriod,
        $employee,
        '2026-01-05',
        $deficit->key,
        'granted',
        'Demora autorizada por supervisión',
        $actor,
    );

    app(PayrollProcessor::class)->processPayPeriod($payPeriod);
    $result = PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->sole();

    expect($result->entry_at->toDateTimeString())->toBe('2026-01-05 06:15:00')
        ->and($result->worked_minutes)->toBe(465)
        ->and($result->scheduled_minutes)->toBe(480)
        ->and($result->recognized_minutes)->toBe(480)
        ->and($result->ordinary_minutes)->toBe(480)
        ->and($result->metadata)->toBe([
            'attendance_exception_ids' => [$exception->id],
            'excused_deficit_minutes' => 15,
        ]);
});

test('processor stores employee identity snapshot and keeps it stable in payroll export', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $employee = processorEmployee($company);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    foreach (['2026-01-05 06:00:30', '2026-01-05 14:30:30'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    app(CurrentCompany::class)->set($company);
    $pending = app(PayrollReadinessChecker::class)->blockers($payPeriod)->sole();
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-01-05');
    $candidate = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(OvertimeDecisionRecorder::class)->decide(
        $payPeriod,
        $employee,
        '2026-01-05',
        $candidate->key,
        'approved',
        'Comprobante con identidad congelada',
        $actor,
    );

    $employee->update([
        'external_id' => 'E-100',
        'first_name' => 'Original',
        'last_name' => 'Employee',
    ]);

    app(PayrollProcessor::class)->processPayPeriod($payPeriod);

    $result = PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->sole();

    $snapshotExternalId = $result->employee_external_id;
    $snapshotName = $result->employee_name;

    $employee->update([
        'external_id' => 'E-999',
        'first_name' => 'Renamed',
        'last_name' => 'Worker',
    ]);

    $path = app(PayrollExcelExporter::class)->export($payPeriod->fresh());
    $data = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

    expect($result->employee_external_id)->toBe('E-100')
        ->and($result->employee_name)->toBe('Original Employee')
        ->and($snapshotExternalId)->toBe('E-100')
        ->and($snapshotName)->toBe('Original Employee')
        ->and($data[5][0])->toBe('E-100')
        ->and($data[5][1])->toBe('Original Employee');

    unlink($path);
});

test('processor rejects an approval made stale by a corrected mark', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $employee = processorEmployee($company);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $entry = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
        ->forUploadedFile($file)->forEmployee($employee)->create([
            'event_at' => '2026-01-05 06:00:00',
            'status' => 'valid',
        ]);
    $exit = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
        ->forUploadedFile($file)->forEmployee($employee)->create([
            'event_at' => '2026-01-05 14:30:00',
            'status' => 'valid',
        ]);

    app(CurrentCompany::class)->set($company);
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-01-05');
    $candidate = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(OvertimeDecisionRecorder::class)->decide(
        $payPeriod, $employee, '2026-01-05', $candidate->key, 'approved', 'Salida validada', $actor,
    );
    $exit->update(['event_at' => '2026-01-05 14:45:00', 'status' => 'corrected']);

    expect(fn () => app(PayrollProcessor::class)->processPayPeriod($payPeriod))
        ->toThrow(PayrollProcessingBlocked::class)
        ->and($payPeriod->fresh()->status)->toBe('ready')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0)
        ->and($entry->fresh()->event_at->toDateTimeString())->toBe('2026-01-05 06:00:00');
});

test('processor assigns an overnight exit in the next period to the starting work date', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $nextPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-06',
        'end_date' => '2026-01-15',
        'status' => 'uploaded',
    ]);
    $employee = processorEmployee($company);
    $profileId = $employee->scheduleAssignments()->value('work_schedule_profile_id');
    WorkSchedule::withoutCompanyScope()
        ->where('work_schedule_profile_id', $profileId)
        ->where('day_of_week', 1)
        ->update(['start_time' => '18:00', 'end_time' => '06:00', 'base_ordinary_hours' => 12]);
    $entryFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $exitFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($nextPeriod)->create();
    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
        ->forUploadedFile($entryFile)->forEmployee($employee)->create([
            'event_at' => '2026-01-05 18:00:00',
            'status' => 'valid',
        ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($nextPeriod)
        ->forUploadedFile($exitFile)->forEmployee($employee)->create([
            'event_at' => '2026-01-06 06:00:00',
            'status' => 'valid',
        ]);

    app(CurrentCompany::class)->set($company);
    app(PayrollProcessor::class)->processPayPeriod($payPeriod);
    $result = PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->sole();

    expect($result->date->toDateString())->toBe('2026-01-05')
        ->and($result->worked_minutes)->toBe(720)
        ->and($result->recognized_minutes)->toBe(720)
        ->and($result->extra_50_minutes)->toBe(360)
        ->and($result->extra_75_minutes)->toBe(360)
        ->and($result->approved_overtime_minutes)->toBe(0)
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $nextPeriod->id)->count())->toBe(0);
});

test('processor skips an active holiday without observed marks', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-06', '2026-01-06');
    processorEmployee($company);
    Holiday::factory()->forCompany($company)->create([
        'date' => '2026-01-06',
        'is_active' => true,
    ]);

    app(CurrentCompany::class)->set($company);
    app(PayrollProcessor::class)->processPayPeriod($payPeriod);

    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
});

test('payroll processor transitions pay period through status flow', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company);
    processorEmployee($company);

    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $report = $processor->processPayPeriod($payPeriod);

    expect($payPeriod->fresh()->status)->toBe('processed')
        ->and($report->employeesProcessed)->toBe(1)
        ->and($report->resultsInserted)->toBeGreaterThan(0);
});

test('processor persists a result row for every employee working day combo', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-11');
    processorEmployee($company);
    processorEmployee($company);

    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $processor->processPayPeriod($payPeriod);

    // Jan 5-11 2026: Mon-Fri + Sat = 6 working days, Sunday non-working.
    $expectedRows = 2 * 6;

    expect(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe($expectedRows);
});

test('processor rejects pay periods that are not ready', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);

    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    expect(fn () => $processor->processPayPeriod($payPeriod))->toThrow(InvalidArgumentException::class);
});

test('processor rejects a pay period whose work dates overlap another period', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-11');
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-11',
        'end_date' => '2026-01-18',
        'status' => 'draft',
    ]);

    app(CurrentCompany::class)->set($company);

    expect(fn () => app(PayrollProcessor::class)->processPayPeriod($payPeriod))
        ->toThrow(InvalidArgumentException::class, 'Las fechas se superponen con otro período de la empresa.')
        ->and($payPeriod->fresh()->status)->toBe('ready')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
});

test('processor stores rules version from config', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    processorEmployee($company);

    config(['payroll.rules_version' => '2026-01']);

    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $processor->processPayPeriod($payPeriod);

    expect(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->first()->rules_version)->toBe('2026-01');
});

test('processor is idempotent and updates existing rows', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    processorEmployee($company);

    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $firstReport = $processor->processPayPeriod($payPeriod);

    // Reset the pay period to ready so the second run is allowed.
    $payPeriod->update(['status' => 'ready']);

    $secondReport = $processor->processPayPeriod($payPeriod);

    expect(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(1)
        ->and($firstReport->resultsInserted)->toBe(1)
        ->and($firstReport->resultsUpdated)->toBe(0)
        ->and($secondReport->resultsInserted)->toBe(0)
        ->and($secondReport->resultsUpdated)->toBe(1);
});

test('processor never touches employees from another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodA = readyPayPeriod($companyA, '2026-01-05', '2026-01-05');
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'ready',
    ]);

    processorEmployee($companyA);
    processorEmployee($companyB);

    app(CurrentCompany::class)->set($companyA);
    $processor = app(PayrollProcessor::class);

    $processor->processPayPeriod($payPeriodA);

    expect(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriodA->id)->count())->toBe(1)
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriodB->id)->count())->toBe(0);
});

test('processor wraps processing in a transaction', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    processorEmployee($company);

    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $processor->processPayPeriod($payPeriod);

    expect($payPeriod->fresh()->status)->toBe('processed');
});

test('processor report counts absence flags', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    processorEmployee($company);

    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $report = $processor->processPayPeriod($payPeriod);

    expect($report->unjustifiedAbsenceCount)->toBe(1)
        ->and($report->daysProcessed)->toBe(1);
});
