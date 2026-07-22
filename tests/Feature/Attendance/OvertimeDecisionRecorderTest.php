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
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\PayrollShiftEvaluation;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\PayrollRules;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('approves exactly the server-calculated candidate in integer minutes', function () {
    $context = overtimeDecisionFixture();

    $decision = app(OvertimeDecisionRecorder::class)->decide(
        $context['period'],
        $context['employee'],
        '2026-07-20',
        $context['candidate_key'],
        OvertimeDecision::APPROVED,
        'Cobertura extraordinaria confirmada',
        $context['actor'],
    );

    expect($decision->segment_kind)->toBe('post_shift')
        ->and($decision->starts_at->toDateTimeString())->toBe('2026-07-20 14:00:00')
        ->and($decision->ends_at->toDateTimeString())->toBe('2026-07-20 14:30:00')
        ->and($decision->minutes)->toBe(30)
        ->and($decision->rate_minutes)->toBe([
            'ordinary' => 0,
            'extra25' => 30,
            'extra50' => 0,
            'extra75' => 0,
            'extra100' => 0,
        ])
        ->and($decision->decision)->toBe(OvertimeDecision::APPROVED)
        ->and($decision->reason)->toBe('Cobertura extraordinaria confirmada')
        ->and($decision->decider->is($context['actor']))->toBeTrue()
        ->and($decision->supersedes_id)->toBeNull();
});

test('changes a decision by appending a superseding audit record', function () {
    $context = overtimeDecisionFixture();
    $recorder = app(OvertimeDecisionRecorder::class);
    $approved = $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::APPROVED, 'Servicio inicialmente confirmado', $context['actor'],
    );
    $rejected = $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::REJECTED, 'Traslado hasta el reloj, no fue trabajo', $context['actor'],
    );

    expect($rejected->supersedes_id)->toBe($approved->id)
        ->and($approved->fresh()->decision)->toBe(OvertimeDecision::APPROVED)
        ->and(OvertimeDecision::current()->sole()->is($rejected))->toBeTrue()
        ->and(OvertimeDecision::query()->count())->toBe(2);
});

test('requires an explicit decision and reason', function (string $decision, string $reason) {
    $context = overtimeDecisionFixture();

    expect(fn () => app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        $decision, $reason, $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(OvertimeDecision::query()->count())->toBe(0);
})->with([
    'unsupported decision' => ['pending', 'Pendiente'],
    'blank reason' => [OvertimeDecision::APPROVED, '   '],
]);

test('rejects a stale candidate key after an observed mark changes', function () {
    $context = overtimeDecisionFixture();
    $context['exit_mark']->update([
        'event_at' => '2026-07-20 14:45:00',
        'status' => 'corrected',
    ]);
    $recorder = app(OvertimeDecisionRecorder::class);

    expect(fn () => $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::APPROVED, 'Clave anterior', $context['actor'],
    ))->toThrow(ValidationException::class);

    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($context['employee'], '2026-07-20');
    $candidate = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $decision = $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $candidate->key,
        OvertimeDecision::APPROVED, 'Marca corregida y validada', $context['actor'],
    );

    expect($candidate->key)->not->toBe($context['candidate_key'])
        ->and($decision->minutes)->toBe(45)
        ->and($decision->rate_minutes['extra25'])->toBe(45);
});

test('an old approval stays stale when a corrected mark returns to its original time', function () {
    $context = overtimeDecisionFixture();
    app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::APPROVED, 'Servicio confirmado originalmente', $context['actor'],
    );

    $context['exit_mark']->update([
        'event_at' => '2026-07-20 14:45:00',
        'status' => 'corrected',
        'metadata' => ['revisions' => [[
            'action' => 'edit_event_at',
            'old_event_at' => '2026-07-20 14:30:00',
            'new_event_at' => '2026-07-20 14:45:00',
        ]]],
    ]);
    $context['exit_mark']->update([
        'event_at' => '2026-07-20 14:30:00',
        'metadata' => ['revisions' => [
            [
                'action' => 'edit_event_at',
                'old_event_at' => '2026-07-20 14:30:00',
                'new_event_at' => '2026-07-20 14:45:00',
            ],
            [
                'action' => 'edit_event_at',
                'old_event_at' => '2026-07-20 14:45:00',
                'new_event_at' => '2026-07-20 14:30:00',
            ],
        ]],
    ]);

    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($context['employee'], '2026-07-20');
    $candidate = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $evaluation = app(PayrollShiftEvaluationResolver::class)->resolve(
        $context['period'],
        $context['employee'],
        '2026-07-20',
    );

    expect($candidate->key)->not->toBe($context['candidate_key'])
        ->and($evaluation->status)->toBe(PayrollShiftEvaluation::BLOCKED)
        ->and($evaluation->blockers->pluck('code')->all())->toBe(['pending_overtime_candidate']);
});

test('an old approval stays stale after attendance facts change without changing the visible pair', function () {
    $context = overtimeDecisionFixture();
    app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::APPROVED, 'Servicio confirmado originalmente', $context['actor'],
    );

    app(AttendanceFactGenerationTracker::class)->advance($context['employee'], '2026-07-20');

    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($context['employee'], '2026-07-20');
    $candidate = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->overtimeCandidates->sole();
    $evaluation = app(PayrollShiftEvaluationResolver::class)->resolve(
        $context['period'],
        $context['employee'],
        '2026-07-20',
    );

    expect($candidate->key)->not->toBe($context['candidate_key'])
        ->and($evaluation->status)->toBe(PayrollShiftEvaluation::BLOCKED)
        ->and($evaluation->blockers->pluck('code')->all())->toBe(['pending_overtime_candidate']);
});

test('requires an active marks manager from the same company', function () {
    $context = overtimeDecisionFixture();
    $withoutPermission = User::factory()->forCompany($context['company'])->create();
    $otherCompany = Company::factory()->create();
    $foreignManager = User::factory()->forCompany($otherCompany)->create()->assignRole('company_admin');
    $inactiveManager = User::factory()->forCompany($context['company'])->inactive()->create()->assignRole('company_admin');

    foreach ([$withoutPermission, $foreignManager, $inactiveManager] as $actor) {
        expect(fn () => app(OvertimeDecisionRecorder::class)->decide(
            $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
            OvertimeDecision::APPROVED, 'Intento no autorizado', $actor,
        ))->toThrow(AuthorizationException::class);
    }

    expect(OvertimeDecision::query()->count())->toBe(0);
});

test('allows a super administrator to decide for the selected company', function () {
    $context = overtimeDecisionFixture();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    $decision = app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::REJECTED, 'Tiempo de traslado no laborado', $superAdmin,
    );

    expect($decision->company_id)->toBe($context['company']->id)
        ->and($decision->decided_by)->toBe($superAdmin->id);
});

test('blocks decisions while the payroll period is locked', function (string $status) {
    $context = overtimeDecisionFixture(periodStatus: $status);

    expect(fn () => app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::APPROVED, 'Intento sobre período bloqueado', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(OvertimeDecision::query()->count())->toBe(0);
})->with(['processing', 'processed', 'approved', 'exported', 'cancelled']);

test('rejects mismatched employee and payroll-period contexts', function () {
    $context = overtimeDecisionFixture();
    $foreignEmployee = Employee::factory()->create();
    $recorder = app(OvertimeDecisionRecorder::class);

    expect(fn () => $recorder->decide(
        $context['period'], $foreignEmployee, '2026-07-20', $context['candidate_key'],
        OvertimeDecision::APPROVED, 'Empleado ajeno', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(fn () => $recorder->decide(
            $context['period'], $context['employee'], '2026-08-01', $context['candidate_key'],
            OvertimeDecision::APPROVED, 'Fecha ajena', $context['actor'],
        ))->toThrow(ValidationException::class)
        ->and(OvertimeDecision::query()->count())->toBe(0);
});

test('rejects a duplicate decision that would not change candidate state', function () {
    $context = overtimeDecisionFixture();
    $recorder = app(OvertimeDecisionRecorder::class);
    $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::APPROVED, 'Servicio confirmado', $context['actor'],
    );

    expect(fn () => $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['candidate_key'],
        OvertimeDecision::APPROVED, 'Misma decisión repetida', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(OvertimeDecision::query()->count())->toBe(1);
});

function overtimeDecisionFixture(string $exitAt = '2026-07-20 14:30:00', string $periodStatus = 'uploaded'): array
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
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada diurna');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-16',
        'end_date' => '2026-07-31',
        'status' => $periodStatus,
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    $marks = collect(['2026-07-20 06:00:00', $exitAt])->map(
        fn (string $eventAt) => RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]),
    );

    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    $holiday = app(PayrollRules::class)->isHoliday($company, $occurrence->workDate);
    $candidate = app(AttendanceShiftAnalyzer::class)
        ->analyze($occurrence, $holiday)
        ->overtimeCandidates
        ->sole();

    return compact('company', 'period', 'employee', 'actor') + [
        'candidate_key' => $candidate->key,
        'exit_mark' => $marks->last(),
    ];
}
