<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\PayrollReadinessChecker;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('reports a pending whole-segment overtime candidate with employee and work date', function () {
    $context = readinessFixture(['2026-01-05 06:00:00', '2026-01-05 14:30:00']);

    $blocker = app(PayrollReadinessChecker::class)->blockers($context['period'])->sole();

    expect($blocker['employee_id'])->toBe($context['employee']->id)
        ->and($blocker['work_date'])->toBe('2026-01-05')
        ->and($blocker['code'])->toBe('pending_overtime_candidate')
        ->and($blocker['candidate_key'])->not->toBeEmpty();
});

test('a rejected complete candidate is reviewed and no longer blocks readiness', function () {
    $context = readinessFixture(['2026-01-05 06:00:00', '2026-01-05 14:30:00']);
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($context['employee'], '2026-01-05');
    $candidate = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $actor = User::factory()->forCompany($context['company'])->create()->assignRole('company_admin');
    app(OvertimeDecisionRecorder::class)->decide(
        $context['period'],
        $context['employee'],
        '2026-01-05',
        $candidate->key,
        OvertimeDecision::REJECTED,
        'Traslado desde el puesto hasta el reloj',
        $actor,
    );

    expect(app(PayrollReadinessChecker::class)->blockers($context['period']))->toBeEmpty();
});

test('reports ambiguous observed marks instead of guessing a pair', function () {
    $context = readinessFixture([
        '2026-01-05 06:00:00',
        '2026-01-05 12:00:00',
        '2026-01-05 14:00:00',
    ]);

    $blocker = app(PayrollReadinessChecker::class)->blockers($context['period'])->sole();

    expect($blocker)->toMatchArray([
        'employee_id' => $context['employee']->id,
        'work_date' => '2026-01-05',
        'code' => 'ambiguous',
    ]);
});

/** @return array{company:Company,period:PayPeriod,employee:Employee} */
function readinessFixture(array $marks): array
{
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'base_ordinary_hours' => 8,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2020-01-01', 'Jornada diurna');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-05',
        'status' => 'uploaded',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    foreach ($marks as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    app(CurrentCompany::class)->set($company);

    return compact('company', 'period', 'employee');
}
