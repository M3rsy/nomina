<?php

use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\AttendanceExceptionRecorder;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\AttendanceSegment;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\GeneralWorkSchedulePublisher;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\PayrollPeriodSnapshotData;
use App\Services\Attendance\PayrollShiftEvaluation;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\Attendance\ShiftOccurrence;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('keeps a legacy overtime approval effective after publication identity is introduced', function () {
    $context = decisionIdentityFixture('2026-07-20 06:00:00', '2026-07-20 14:30:00');
    $candidate = $context['review']->analysis->overtimeCandidates->sole();
    $legacy = legacyOvertimeDecision($context, $candidate, OvertimeDecision::APPROVED);

    $review = app(PayrollShiftEvaluationResolver::class)
        ->review($context['period'], $context['employee'], '2026-07-20');
    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20');

    expect($legacy->fingerprint)->not->toBe($candidate->fingerprint)
        ->and($legacy->candidate_key)->not->toBe($candidate->key)
        ->and($review->decisionFor($review->analysis->overtimeCandidates->sole())?->is($legacy))->toBeTrue()
        ->and($evaluation->status)->toBe(PayrollShiftEvaluation::PROCESSABLE)
        ->and($evaluation->approvedOvertimeMinutes)->toBe(30);
});

test('keeps a legacy attendance exception effective in review and payroll evaluation', function () {
    $context = decisionIdentityFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $deficit = $context['review']->analysis->deficits->sole();
    $legacy = legacyAttendanceException($context, $deficit, AttendanceException::GRANTED);

    $review = app(PayrollShiftEvaluationResolver::class)
        ->review($context['period'], $context['employee'], '2026-07-20');
    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20');

    expect($review->exceptionFor($review->analysis->deficits->sole())?->is($legacy))->toBeTrue()
        ->and($evaluation->recognizedMinutes)->toBe(480)
        ->and($evaluation->excusedDeficitMinutes)->toBe(15);
});

test('keeps a schedule overlap overtime approval after real duration first activation on the same work date', function () {
    $context = policyTransitionFixture('2026-07-20 06:00:00', '2026-07-20 14:30:00');
    $legacyCandidate = $context['review']->analysis->overtimeCandidates->sole();
    $legacyDecision = app(OvertimeDecisionRecorder::class)->decide(
        $context['period'],
        $context['employee'],
        '2026-07-20',
        $legacyCandidate->key,
        OvertimeDecision::APPROVED,
        'Approved before duration-first activation',
        $context['actor'],
    );

    app(GeneralWorkSchedulePublisher::class)->activate(
        $context['company'],
        $context['actor'],
        'Activate duration-first payroll',
        '2026-07-19',
    );

    $employees = Employee::withoutCompanyScope()->whereKey($context['employee']->id)->get();
    $snapshot = PayrollPeriodSnapshotData::capture($context['period'], $employees);
    $review = app(PayrollShiftEvaluationResolver::class)
        ->review($context['period'], $context['employee'], '2026-07-20', snapshot: $snapshot);
    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20', snapshot: $snapshot);
    $currentCandidate = $review->analysis->overtimeCandidates->sole();

    expect($context['review']->occurrence->payrollPolicyKey)->toBe('schedule-overlap-v1')
        ->and($review->occurrence->payrollPolicyKey)->toBe('duration-first-v2')
        ->and($currentCandidate->kind)->toBe('post_quota_overtime')
        ->and($currentCandidate->start?->equalTo($legacyCandidate->start))->toBeTrue()
        ->and($currentCandidate->end?->equalTo($legacyCandidate->end))->toBeTrue()
        ->and($currentCandidate->rateMinutes)->toEqual($legacyCandidate->rateMinutes)
        ->and($review->decisionFor($currentCandidate)?->is($legacyDecision))->toBeTrue()
        ->and($evaluation->status)->toBe(PayrollShiftEvaluation::PROCESSABLE)
        ->and($evaluation->approvedOvertimeMinutes)->toBe(30);
});

test('keeps a schedule overlap attendance grant after real duration first activation on the same work date', function () {
    $context = policyTransitionFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $legacyDeficit = $context['review']->analysis->deficits->sole();
    $legacyException = app(AttendanceExceptionRecorder::class)->decide(
        $context['period'],
        $context['employee'],
        '2026-07-20',
        $legacyDeficit->key,
        AttendanceException::GRANTED,
        'Granted before duration-first activation',
        $context['actor'],
    );

    app(GeneralWorkSchedulePublisher::class)->activate(
        $context['company'],
        $context['actor'],
        'Activate duration-first payroll',
        '2026-07-19',
    );

    $review = app(PayrollShiftEvaluationResolver::class)
        ->review($context['period'], $context['employee'], '2026-07-20');
    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20');
    $currentDeficit = $review->analysis->deficits->sole();

    expect($legacyDeficit->kind)->toBe('late_arrival')
        ->and($currentDeficit->kind)->toBe('daily_shortfall')
        ->and($currentDeficit->minutes)->toBe($legacyDeficit->minutes)
        ->and($currentDeficit->rateMinutes)->toEqual($legacyDeficit->rateMinutes)
        ->and($review->exceptionFor($currentDeficit)?->is($legacyException))->toBeTrue()
        ->and($evaluation->status)->toBe(PayrollShiftEvaluation::PROCESSABLE)
        ->and($evaluation->recognizedMinutes)->toBe(480)
        ->and($evaluation->excusedDeficitMinutes)->toBe(15);
});

test('rejects a legacy key when it is not paired with the released legacy fingerprint', function () {
    $context = decisionIdentityFixture('2026-07-20 06:00:00', '2026-07-20 14:30:00');
    $candidate = $context['review']->analysis->overtimeCandidates->sole();
    legacyOvertimeDecision($context, $candidate, OvertimeDecision::APPROVED, $candidate->fingerprint);

    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20');

    expect($evaluation->status)->toBe(PayrollShiftEvaluation::BLOCKED);
});

test('rejects a canonical key when it is paired with a legacy fingerprint', function () {
    $context = decisionIdentityFixture('2026-07-20 06:00:00', '2026-07-20 14:30:00');
    $candidate = $context['review']->analysis->overtimeCandidates->sole();
    legacyOvertimeDecision(
        $context,
        $candidate,
        OvertimeDecision::APPROVED,
        releasedLegacyFingerprint($context['review']->occurrence),
        $candidate->key,
    );

    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20');

    expect($evaluation->status)->toBe(PayrollShiftEvaluation::BLOCKED);
});

test('does not carry a schedule overlap approval across activation after evidence generation changes', function () {
    $context = policyTransitionFixture('2026-07-20 06:00:00', '2026-07-20 14:30:00');
    $candidate = $context['review']->analysis->overtimeCandidates->sole();
    app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $candidate->key,
        OvertimeDecision::APPROVED, 'Approved before duration-first activation', $context['actor'],
    );
    app(GeneralWorkSchedulePublisher::class)->activate(
        $context['company'], $context['actor'], 'Activate duration-first payroll', '2026-07-19',
    );
    app(AttendanceFactGenerationTracker::class)->advance($context['employee'], '2026-07-20');

    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20');

    expect($evaluation->status)->toBe(PayrollShiftEvaluation::BLOCKED)
        ->and($evaluation->blockers->pluck('code')->all())->toBe(['pending_overtime_candidate']);
});

test('supersedes a matching legacy overtime root with a canonical decision', function () {
    $context = decisionIdentityFixture('2026-07-20 06:00:00', '2026-07-20 14:30:00');
    $candidate = $context['review']->analysis->overtimeCandidates->sole();
    $legacy = legacyOvertimeDecision($context, $candidate, OvertimeDecision::APPROVED);

    $current = app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $candidate->key,
        OvertimeDecision::REJECTED, 'Corrected audited decision', $context['actor'],
    );

    expect($current->candidate_key)->toBe($candidate->key)
        ->and($current->supersedes_id)->toBe($legacy->id)
        ->and(OvertimeDecision::current()->sole()->is($current))->toBeTrue();
});

test('supersedes a verified schedule overlap root with a canonical duration first decision', function () {
    $context = policyTransitionFixture('2026-07-20 06:00:00', '2026-07-20 14:30:00');
    $legacyCandidate = $context['review']->analysis->overtimeCandidates->sole();
    $legacy = app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $legacyCandidate->key,
        OvertimeDecision::APPROVED, 'Approved before duration-first activation', $context['actor'],
    );
    app(GeneralWorkSchedulePublisher::class)->activate(
        $context['company'], $context['actor'], 'Activate duration-first payroll', '2026-07-19',
    );
    $currentCandidate = app(PayrollShiftEvaluationResolver::class)
        ->review($context['period'], $context['employee'], '2026-07-20')
        ->analysis->overtimeCandidates->sole();

    $current = app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $currentCandidate->key,
        OvertimeDecision::REJECTED, 'Rejected after audited policy activation', $context['actor'],
    );

    expect($current->record_version)->toBe(2)
        ->and($current->candidate_key)->toBe($currentCandidate->key)
        ->and($current->fingerprint)->toBe($currentCandidate->fingerprint)
        ->and($current->supersedes_id)->toBe($legacy->id)
        ->and(OvertimeDecision::current()->sole()->is($current))->toBeTrue();
});

test('supersedes a matching legacy attendance exception root', function () {
    $context = decisionIdentityFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $deficit = $context['review']->analysis->deficits->sole();
    $legacy = legacyAttendanceException($context, $deficit, AttendanceException::GRANTED);

    $current = app(AttendanceExceptionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $deficit->key,
        AttendanceException::REVOKED, 'Authorization revoked after migration', $context['actor'],
    );

    expect($current->deficit_key)->toBe($deficit->key)
        ->and($current->supersedes_id)->toBe($legacy->id)
        ->and(AttendanceException::current()->sole()->is($current))->toBeTrue();
});

test('supersedes a verified schedule overlap grant with a canonical duration first revocation', function () {
    $context = policyTransitionFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $legacyDeficit = $context['review']->analysis->deficits->sole();
    $legacy = app(AttendanceExceptionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $legacyDeficit->key,
        AttendanceException::GRANTED, 'Granted before duration-first activation', $context['actor'],
    );
    app(GeneralWorkSchedulePublisher::class)->activate(
        $context['company'], $context['actor'], 'Activate duration-first payroll', '2026-07-19',
    );
    $currentDeficit = app(PayrollShiftEvaluationResolver::class)
        ->review($context['period'], $context['employee'], '2026-07-20')
        ->analysis->deficits->sole();

    $current = app(AttendanceExceptionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $currentDeficit->key,
        AttendanceException::REVOKED, 'Revoked after audited policy activation', $context['actor'],
    );

    expect($current->record_version)->toBe(2)
        ->and($current->deficit_key)->toBe($currentDeficit->key)
        ->and($current->fingerprint)->toBe($currentDeficit->fingerprint)
        ->and($current->supersedes_id)->toBe($legacy->id)
        ->and(AttendanceException::current()->sole()->is($current))->toBeTrue();
});

test('does not reactivate a legacy approval after attendance fact generation changes', function () {
    $context = decisionIdentityFixture('2026-07-20 06:00:00', '2026-07-20 14:30:00');
    legacyOvertimeDecision($context, $context['review']->analysis->overtimeCandidates->sole(), OvertimeDecision::APPROVED);
    app(AttendanceFactGenerationTracker::class)->advance($context['employee'], '2026-07-20');

    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20');

    expect($evaluation->status)->toBe(PayrollShiftEvaluation::BLOCKED)
        ->and($evaluation->blockers->pluck('code')->all())->toBe(['pending_overtime_candidate']);
});

test('does not apply a legacy exception after its observed mark changes', function () {
    $context = decisionIdentityFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    legacyAttendanceException($context, $context['review']->analysis->deficits->sole(), AttendanceException::GRANTED);
    $context['review']->occurrence->entryMark()?->update([
        'event_at' => '2026-07-20 06:30:00',
        'status' => 'corrected',
    ]);

    $evaluation = app(PayrollShiftEvaluationResolver::class)
        ->resolve($context['period'], $context['employee'], '2026-07-20');

    expect($evaluation->excusedDeficitMinutes)->toBe(0)
        ->and($evaluation->recognizedMinutes)->toBe(450);
});

function decisionIdentityFixture(string $entryAt, string $exitAt): array
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
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Day shift');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-20',
        'status' => 'uploaded',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    foreach ([$entryAt, $exitAt] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $review = app(PayrollShiftEvaluationResolver::class)->review($period, $employee, '2026-07-20');

    return compact('company', 'employee', 'period', 'actor', 'review');
}

function policyTransitionFixture(string $entryAt, string $exitAt): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $profile = WorkScheduleProfile::withoutEvents(
        fn () => WorkScheduleProfile::factory()->forCompany($company)->create([
            'profile_key' => 'general',
            'version' => 1,
        ]),
    );
    $publication = WorkScheduleProfilePublication::createLegacyFor($profile);
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'base_ordinary_hours' => 8,
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'General schedule');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-20',
        'status' => 'uploaded',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    foreach ([$entryAt, $exitAt] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }
    $review = app(PayrollShiftEvaluationResolver::class)->review($period, $employee, '2026-07-20');

    expect($review->occurrence->publicationId)->toBe($publication->id);

    return compact('company', 'employee', 'period', 'actor', 'review');
}

function legacyOvertimeDecision(
    array $context,
    AttendanceSegment $candidate,
    string $decision,
    ?string $storedFingerprint = null,
    ?string $storedKey = null,
): OvertimeDecision {
    $fingerprint = releasedLegacyFingerprint($context['review']->occurrence);

    return OvertimeDecision::withoutCompanyScope()->create([
        'record_version' => 1,
        'company_id' => $context['company']->id,
        'pay_period_id' => $context['period']->id,
        'employee_id' => $context['employee']->id,
        'work_date' => '2026-07-20',
        'candidate_key' => $storedKey ?? releasedLegacyKey($candidate, $fingerprint),
        'fingerprint' => $storedFingerprint ?? $fingerprint,
        'segment_kind' => $candidate->kind,
        'starts_at' => $candidate->start,
        'ends_at' => $candidate->end,
        'minutes' => $candidate->minutes,
        'rate_minutes' => segmentRates($candidate),
        'decision' => $decision,
        'reason' => 'Approved before publication identity deployment',
        'decided_by' => $context['actor']->id,
    ]);
}

function legacyAttendanceException(array $context, AttendanceSegment $deficit, string $decision): AttendanceException
{
    $fingerprint = releasedLegacyFingerprint($context['review']->occurrence);

    return AttendanceException::withoutCompanyScope()->create([
        'record_version' => 1,
        'company_id' => $context['company']->id,
        'pay_period_id' => $context['period']->id,
        'employee_id' => $context['employee']->id,
        'work_date' => '2026-07-20',
        'deficit_key' => releasedLegacyKey($deficit, $fingerprint),
        'fingerprint' => $fingerprint,
        'segment_kind' => $deficit->kind,
        'starts_at' => $deficit->start,
        'ends_at' => $deficit->end,
        'minutes' => $deficit->minutes,
        'rate_minutes' => segmentRates($deficit),
        'decision' => $decision,
        'reason' => 'Granted before publication identity deployment',
        'decided_by' => $context['actor']->id,
    ]);
}

function releasedLegacyFingerprint(ShiftOccurrence $occurrence, bool $isHoliday = false, int $calendarGeneration = 0): string
{
    $revision = fn (?RawMark $mark): string => hash(
        'sha256',
        json_encode($mark?->metadata['revisions'] ?? [], JSON_THROW_ON_ERROR),
    );
    $parts = [
        $occurrence->assignment?->id,
        $occurrence->schedule?->id,
        $occurrence->schedule?->start_time,
        $occurrence->schedule?->end_time,
        json_encode($occurrence->schedule?->banding_json),
        $occurrence->workDate->toDateString(),
        $isHoliday ? 'holiday' : 'regular',
        $occurrence->factGeneration,
        $occurrence->entryMark()?->id,
        $occurrence->entryMark()?->event_at?->toIso8601String(),
        $revision($occurrence->entryMark()),
        $occurrence->exitMark()?->id,
        $occurrence->exitMark()?->event_at?->toIso8601String(),
        $revision($occurrence->exitMark()),
    ];
    if ($calendarGeneration > 0) {
        $parts[] = $calendarGeneration;
    }

    return hash('sha256', implode('|', $parts));
}

function releasedLegacyKey(AttendanceSegment $segment, string $fingerprint): string
{
    $identity = $segment->start === null
        ? [$segment->kind, 'non-interval', $segment->minutes, $fingerprint]
        : [$segment->kind, $segment->start->toIso8601String(), $segment->end->toIso8601String(), $fingerprint];

    return hash('sha256', implode('|', $identity));
}

function segmentRates(AttendanceSegment $segment): array
{
    return [
        'ordinary' => $segment->rateMinutes->ordinaryMinutes,
        'extra25' => $segment->rateMinutes->extra25Minutes,
        'extra50' => $segment->rateMinutes->extra50Minutes,
        'extra75' => $segment->rateMinutes->extra75Minutes,
        'extra100' => $segment->rateMinutes->extra100Minutes,
    ];
}
