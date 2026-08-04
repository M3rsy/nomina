<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\AttendanceExceptionRecorder;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\HolidayCalendar;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\PayrollReadinessChecker;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\Attendance\VariationAcknowledgementRecorder;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayPeriodReopener;
use App\Services\Payroll\PayrollExcelExporter;
use App\Services\Payroll\PayrollProcessingBlocked;
use App\Services\Payroll\PayrollProcessor;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

test('processor blocks a missing effective profile without writing payroll results', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $employee = processorEmployee($company);
    $profileId = $employee->scheduleAssignments()->value('work_schedule_profile_id');
    $missingProfileId = $profileId + 10_000;

    DB::statement('PRAGMA defer_foreign_keys=ON');
    DB::table('employee_schedule_assignments')->where('work_schedule_profile_id', $profileId)
        ->update(['work_schedule_profile_id' => $missingProfileId]);
    DB::table('work_schedules')->where('work_schedule_profile_id', $profileId)
        ->update(['work_schedule_profile_id' => $missingProfileId]);
    DB::table('work_schedule_profile_publications')->where('profile_id', $profileId)
        ->update(['profile_id' => $missingProfileId]);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(PayrollProcessor::class)->processPayPeriod($payPeriod))
        ->toThrow(PayrollProcessingBlocked::class)
        ->and($payPeriod->fresh()->status)->toBe('ready')
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->count())->toBe(0);
});

test('processor blocks assignments that become ambiguous during context resolution', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $employee = processorEmployee($company);
    $assignment = $employee->scheduleAssignments()->sole();
    $inserted = false;

    WorkScheduleProfilePublication::retrieved(function () use ($assignment, &$inserted): void {
        if ($inserted) {
            return;
        }

        $inserted = true;
        EmployeeScheduleAssignment::factory()->create([
            'company_id' => $assignment->company_id,
            'employee_id' => $assignment->employee_id,
            'work_schedule_profile_id' => $assignment->work_schedule_profile_id,
            'effective_from' => $assignment->effective_from->addDay(),
            'effective_to' => $assignment->effective_to,
            'reason' => 'Concurrent overlapping assignment',
        ]);
    });
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
            'work_schedule_profile_publication_id' => $occurrence->publicationId,
            'payroll_policy_key' => 'schedule-overlap-v1',
            'attendance_exception_ids' => [$exception->id],
            'excused_deficit_minutes' => 15,
        ]);
});

test('processor freezes complete audited duration-first facts in the daily snapshot', function () {
    Carbon::setTestNow('2026-07-20 20:00:00');
    $company = Company::factory()->create();
    $period = readyPayPeriod($company, '2026-07-20', '2026-07-20');
    $employee = processorEmployee($company);
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profileId = $employee->scheduleAssignments()->value('work_schedule_profile_id');
    $assignmentId = $employee->scheduleAssignments()->value('id');
    $scheduleId = WorkSchedule::withoutCompanyScope()->where('work_schedule_profile_id', $profileId)
        ->where('day_of_week', 1)->value('id');
    DB::table('work_schedule_profile_publications')->where('profile_id', $profileId)->update([
        'payroll_policy_key' => 'duration-first-v2',
        'published_by' => $actor->id,
    ]);
    $publication = WorkScheduleProfilePublication::withoutCompanyScope()->where('profile_id', $profileId)->sole();
    $revision = [[
        'action' => 'correct_time',
        'user_id' => $actor->id,
        'reason' => 'Verified against source device',
        'at' => '2026-07-20 19:30:00',
    ]];
    $entry = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
        ->forEmployee($employee)->create([
            'event_at' => '2026-07-20 09:00:00',
            'source' => 'glg',
            'status' => 'corrected',
            'metadata' => ['revisions' => $revision],
        ]);
    $exit = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
        ->forEmployee($employee)->create([
            'event_at' => '2026-07-20 19:00:00',
            'source' => 'glg',
            'status' => 'valid',
            'metadata' => null,
        ]);
    app(CurrentCompany::class)->set($company);
    $review = app(PayrollShiftEvaluationResolver::class)->review($period, $employee, '2026-07-20');
    $candidate = $review->analysis->overtimeCandidates->sole();
    $variation = $review->analysis->variations->sole();
    $decision = app(OvertimeDecisionRecorder::class)->approvePartial(
        $period, $employee, '2026-07-20', $candidate->key,
        '2026-07-20 17:30:00', '2026-07-20 18:30:00',
        'Approved exact cross-band interval', $actor,
    );
    $acknowledgement = app(VariationAcknowledgementRecorder::class)->acknowledge(
        $period, $employee, '2026-07-20', $variation->key, $variation->fingerprint,
        'Entry variation reviewed', $actor,
    );
    config(['payroll.rules_version' => 'duration-first-v2.1']);

    app(PayrollProcessor::class)->processPayPeriod($period);
    $result = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->sole();
    $snapshot = $result->day_snapshot;

    expect($snapshot['publication'])->toBe([
        'id' => $publication->id,
        'payroll_policy_key' => 'duration-first-v2',
        'assignment_id' => $assignmentId,
        'profile_id' => $profileId,
        'schedule_id' => $scheduleId,
    ])->and($snapshot['rules_version'])->toBe('duration-first-v2.1')
        ->and($snapshot['attendance'])->toMatchArray([
            'worked_minutes' => 600,
            'scheduled_minutes' => 480,
            'recognized_minutes' => 540,
            'excluded_transfer_minutes' => 0,
        ])->and($snapshot['attendance']['marks'])->toBe([
            [
                'id' => $entry->id,
                'event_at' => '2026-07-20 09:00:00',
                'status' => 'corrected',
                'source' => 'glg',
                'employee_id' => $employee->id,
                'employee_external_id' => $entry->employee_external_id,
                'revisions' => $revision,
            ],
            [
                'id' => $exit->id,
                'event_at' => '2026-07-20 19:00:00',
                'status' => 'valid',
                'source' => 'glg',
                'employee_id' => $employee->id,
                'employee_external_id' => $exit->employee_external_id,
                'revisions' => [],
            ],
        ])->and($snapshot['payable_minutes'])->toBe([
            'ordinary' => 480, 'extra25' => 30, 'extra50' => 30, 'extra75' => 0, 'extra100' => 0,
        ])->and($snapshot['overtime'][0]['candidate'])->toMatchArray([
            'key' => $candidate->key,
            'fingerprint' => $candidate->fingerprint,
            'kind' => 'post_quota_overtime',
            'starts_at' => '2026-07-20 17:00:00',
            'ends_at' => '2026-07-20 19:00:00',
            'minutes' => 120,
            'rate_minutes' => ['ordinary' => 0, 'extra25' => 60, 'extra50' => 60, 'extra75' => 0, 'extra100' => 0],
        ])->and($snapshot['overtime'][0]['decision'])->toMatchArray([
            'id' => $decision->id,
            'record_version' => 2,
            'candidate_key' => $candidate->key,
            'fingerprint' => $candidate->fingerprint,
            'segment_kind' => 'post_quota_overtime',
            'decision' => 'approved',
            'reason' => 'Approved exact cross-band interval',
            'decided_by' => $actor->id,
            'resolution_kind' => 'partial',
            'approved_starts_at' => '2026-07-20 17:30:00',
            'approved_ends_at' => '2026-07-20 18:30:00',
            'rejected_before_starts_at' => '2026-07-20 17:00:00',
            'rejected_before_ends_at' => '2026-07-20 17:30:00',
            'rejected_after_starts_at' => '2026-07-20 18:30:00',
            'rejected_after_ends_at' => '2026-07-20 19:00:00',
            'approved_minutes' => 60,
            'rejected_minutes' => 60,
            'rejected_before_minutes' => 30,
            'rejected_after_minutes' => 30,
            'approved_rate_minutes' => ['ordinary' => 0, 'extra25' => 30, 'extra50' => 30, 'extra75' => 0, 'extra100' => 0],
            'rejected_rate_minutes' => ['ordinary' => 0, 'extra25' => 30, 'extra50' => 30, 'extra75' => 0, 'extra100' => 0],
            'resolution_hash' => $decision->resolution_hash,
            'created_at' => '2026-07-20 20:00:00',
        ])->and(collect($snapshot['variations'][0])->except('acknowledgement')->all())->toMatchArray([
            'key' => $variation->key,
            'fingerprint' => $variation->fingerprint,
            'kind' => 'schedule_entry',
            'entry_at' => '2026-07-20 09:00:00',
        ])->and($snapshot['variations'][0]['acknowledgement'])->toMatchArray([
            'id' => $acknowledgement->id,
            'record_version' => 2,
            'variation_key' => $variation->key,
            'fingerprint' => $variation->fingerprint,
            'variation_kind' => 'schedule_entry',
            'entry_at' => '2026-07-20 09:00:00',
            'reason' => 'Entry variation reviewed',
            'acknowledged_by' => $actor->id,
            'created_at' => '2026-07-20 20:00:00',
        ]);
});

test('processor freezes the resolved shortfall state and audit reason', function () {
    Carbon::setTestNow('2026-07-20 16:00:00');
    $company = Company::factory()->create();
    $period = readyPayPeriod($company, '2026-07-20', '2026-07-20');
    $employee = processorEmployee($company);
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profileId = $employee->scheduleAssignments()->value('work_schedule_profile_id');
    DB::table('work_schedule_profile_publications')->where('profile_id', $profileId)->update([
        'payroll_policy_key' => 'duration-first-v2',
        'published_by' => $actor->id,
    ]);
    foreach (['2026-07-20 07:00:00', '2026-07-20 14:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
            ->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']);
    }
    app(CurrentCompany::class)->set($company);
    $deficit = app(PayrollShiftEvaluationResolver::class)
        ->review($period, $employee, '2026-07-20')->analysis->deficits->sole();
    $decision = app(AttendanceExceptionRecorder::class)->decide(
        $period, $employee, '2026-07-20', $deficit->key, 'rejected',
        'Unpaid incomplete daily quota', $actor,
    );

    app(PayrollProcessor::class)->processPayPeriod($period);
    $snapshot = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->sole()->day_snapshot;
    $shortfall = collect($snapshot['shortfalls'])->sole();

    expect($snapshot['attendance'])->toMatchArray([
        'worked_minutes' => 420,
        'recognized_minutes' => 420,
    ])->and($snapshot['payable_minutes'])->toBe([
        'ordinary' => 420, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0,
    ])->and($shortfall)->toMatchArray([
        'state' => 'rejected',
        'reason' => 'Unpaid incomplete daily quota',
        'fact' => [
            'key' => $deficit->key,
            'fingerprint' => $deficit->fingerprint,
            'kind' => 'daily_shortfall',
            'starts_at' => null,
            'ends_at' => null,
            'minutes' => 60,
            'rate_minutes' => ['ordinary' => 60, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
        ],
        'decision' => [
            'id' => $decision->id,
            'record_version' => 2,
            'deficit_key' => $deficit->key,
            'fingerprint' => $deficit->fingerprint,
            'segment_kind' => 'daily_shortfall',
            'starts_at' => null,
            'ends_at' => null,
            'minutes' => 60,
            'rate_minutes' => ['ordinary' => 60, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
            'decision' => 'rejected',
            'reason' => 'Unpaid incomplete daily quota',
            'decided_by' => $actor->id,
            'supersedes_id' => null,
            'created_at' => '2026-07-20 16:00:00',
        ],
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

test('processor stores the captured calendar generation for every result date', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-06');
    processorEmployee($company);
    app(HolidayCalendar::class)->save($company, null, [
        'date' => '2026-01-06',
        'name' => 'Inactive calendar fact',
        'description' => null,
        'is_active' => false,
    ]);

    app(CurrentCompany::class)->set($company);
    app(PayrollProcessor::class)->processPayPeriod($payPeriod);

    $generations = PayrollResult::withoutCompanyScope()
        ->where('pay_period_id', $payPeriod->id)
        ->get()
        ->mapWithKeys(fn (PayrollResult $result): array => [
            $result->date->toDateString() => $result->calendar_generation,
        ]);

    expect($generations->all())->toBe([
        '2026-01-05' => 0,
        '2026-01-06' => 1,
    ]);
});

test('processor reuses an identical immutable snapshot row without rewriting it', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    processorEmployee($company);

    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    Carbon::setTestNow('2026-01-12 08:00:00');
    $firstReport = $processor->processPayPeriod($payPeriod);
    $firstResult = PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->sole();

    // Reset the pay period to ready so the second run is allowed.
    $payPeriod->update(['status' => 'ready']);
    Carbon::setTestNow('2026-01-12 09:00:00');
    $secondReport = $processor->processPayPeriod($payPeriod);
    $retriedResult = PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->sole();

    expect($retriedResult->id)->toBe($firstResult->id)
        ->and($retriedResult->result_generation)->toBe(1)
        ->and($retriedResult->snapshot_hash)->toBeString()->toHaveLength(64)
        ->and($retriedResult->snapshot_hash)->toBe($firstResult->snapshot_hash)
        ->and($retriedResult->created_at->equalTo($firstResult->created_at))->toBeTrue()
        ->and($retriedResult->updated_at->equalTo($firstResult->updated_at))->toBeTrue()
        ->and($firstReport->resultsInserted)->toBe(1)
        ->and($firstReport->resultsUpdated)->toBe(0)
        ->and($secondReport->resultsInserted)->toBe(0)
        ->and($secondReport->resultsUpdated)->toBe(0)
        ->and($secondReport->resultsReused)->toBe(1);
});

test('processor rejects a conflicting retry and preserves the immutable snapshot', function () {
    $company = Company::factory()->create();
    $payPeriod = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    $employee = processorEmployee($company);
    app(CurrentCompany::class)->set($company);

    app(PayrollProcessor::class)->processPayPeriod($payPeriod);
    $original = PayrollResult::withoutCompanyScope()->where('pay_period_id', $payPeriod->id)->sole();
    $immutableColumns = ['id', 'rules_version', 'day_snapshot', 'snapshot_hash', 'created_at', 'updated_at'];
    $originalAttributes = collect($original->getRawOriginal())->only($immutableColumns)->all();

    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    foreach (['2026-01-05 06:00:00', '2026-01-05 14:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)
            ->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']);
    }
    $payPeriod->update(['status' => 'ready']);

    expect(fn () => app(PayrollProcessor::class)->processPayPeriod($payPeriod))
        ->toThrow(LogicException::class, 'Conflicting immutable payroll result already exists.')
        ->and($payPeriod->fresh()->status)->toBe('ready')
        ->and(collect($original->fresh()->getRawOriginal())->only($immutableColumns)->all())->toBe($originalAttributes);
});

test('audited reopen appends a complete immutable generation and selects it as current', function () {
    $company = Company::factory()->create();
    $period = readyPayPeriod($company, '2026-01-05', '2026-01-06');
    $employee = processorEmployee($company);
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $processor->processPayPeriod($period);
    $originals = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->orderBy('date')->get();
    app(PayPeriodReopener::class)->reopen($period, 'Correct attendance facts', $actor);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    foreach (['2026-01-05 06:00:00', '2026-01-05 14:00:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
            ->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']);
    }
    $period->update(['status' => 'ready']);

    $processor->processPayPeriod($period);
    $current = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->orderBy('date')->get();
    $history = PayrollResult::withoutCompanyScope()->withoutGlobalScope('currentGeneration')
        ->where('pay_period_id', $period->id)->orderBy('result_generation')->orderBy('date')->get();

    expect($history)->toHaveCount(4)
        ->and($originals->pluck('result_generation')->all())->toBe([1, 1])
        ->and($current->pluck('result_generation')->all())->toBe([2, 2])
        ->and($current->pluck('supersedes_id')->all())->toBe($originals->pluck('id')->all())
        ->and($history->take(2)->pluck('id')->all())->toBe($originals->pluck('id')->all())
        ->and($period->fresh()->current_result_generation)->toBe(2)
        ->and($period->fresh()->authorized_result_generation)->toBeNull();
});

test('audited generation carries surviving rows and excludes removed days from current results', function () {
    $company = Company::factory()->create();
    $period = readyPayPeriod($company, '2026-01-05', '2026-01-06');
    processorEmployee($company);
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $processor->processPayPeriod($period);
    $monday = PayrollResult::withoutCompanyScope()->whereDate('date', '2026-01-05')->sole();
    app(PayPeriodReopener::class)->reopen($period, 'Exclude a new holiday', $actor);
    Holiday::factory()->forCompany($company)->create(['date' => '2026-01-06', 'is_active' => true]);
    $period->update(['status' => 'ready']);
    $processor->processPayPeriod($period);

    $current = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->get();
    $history = PayrollResult::withoutCompanyScope()->withoutGlobalScope('currentGeneration')
        ->where('pay_period_id', $period->id)->get();

    expect($current)->toHaveCount(1)
        ->and($current->sole()->date->toDateString())->toBe('2026-01-05')
        ->and($current->sole()->result_generation)->toBe(2)
        ->and($current->sole()->supersedes_id)->toBe($monday->id)
        ->and($history)->toHaveCount(3);
});

test('blocked audited generation rolls back appended rows and retains the committed generation', function () {
    $company = Company::factory()->create();
    $period = readyPayPeriod($company, '2026-01-05', '2026-01-06');
    $employee = processorEmployee($company);
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);
    $processor = app(PayrollProcessor::class);

    $processor->processPayPeriod($period);
    $originalIds = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->pluck('id')->all();
    app(PayPeriodReopener::class)->reopen($period, 'Review pending overtime', $actor);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    foreach (['2026-01-06 06:00:00', '2026-01-06 14:30:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
            ->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']);
    }
    $period->update(['status' => 'ready']);

    expect(fn () => $processor->processPayPeriod($period))->toThrow(PayrollProcessingBlocked::class)
        ->and($period->fresh()->current_result_generation)->toBe(1)
        ->and($period->fresh()->authorized_result_generation)->toBe(2)
        ->and(PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->pluck('id')->all())
        ->toBe($originalIds)
        ->and(PayrollResult::withoutCompanyScope()->withoutGlobalScope('currentGeneration')
            ->where('pay_period_id', $period->id)->count())->toBe(2);
});

test('processed payroll results reject model and direct database mutation', function () {
    $company = Company::factory()->create();
    $period = readyPayPeriod($company, '2026-01-05', '2026-01-05');
    processorEmployee($company);
    app(CurrentCompany::class)->set($company);
    app(PayrollProcessor::class)->processPayPeriod($period);
    $result = PayrollResult::withoutCompanyScope()->where('pay_period_id', $period->id)->sole();
    $original = $result->getRawOriginal();

    expect(fn () => $result->update(['notes' => 'rewritten']))
        ->toThrow(LogicException::class, 'Payroll results are insert-only.')
        ->and(fn () => $result->delete())
        ->toThrow(LogicException::class, 'Payroll results are insert-only.')
        ->and(fn () => DB::table('payroll_results')->where('id', $result->id)->update(['notes' => 'rewritten']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_results')->where('id', $result->id)->delete())
        ->toThrow(QueryException::class)
        ->and($result->fresh()->getRawOriginal())->toBe($original);
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
