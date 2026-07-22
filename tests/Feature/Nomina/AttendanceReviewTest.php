<?php

use App\Livewire\Nomina\Revisar;
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
use App\Services\Attendance\AttendanceExceptionRecorder;
use App\Services\Attendance\AttendanceReviewQuery;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('shows exact server-calculated overtime candidates beside observed and scheduled time', function () {
    $context = attendanceReviewPageFixture();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('overtimeReviews', fn ($reviews) => $reviews->count() === 1)
        ->assertSee('Autorizaciones de horas extra')
        ->assertSee('El sistema calcula el tramo completo')
        ->assertSee('María Guardia')
        ->assertSee('Jornada asignada')
        ->assertSee('06:00 → 14:00')
        ->assertSee('Marcas observadas')
        ->assertSee('06:00 → 14:30')
        ->assertSee('Salida posterior')
        ->assertSee('30 min · 0,50 h')
        ->assertSee('25%: 30 min')
        ->assertSee('Pendiente de decisión')
        ->assertSee('Aprobar completo')
        ->assertSee('Rechazar completo');
});

test('shows exact attendance deficits without changing the observed marks', function () {
    $context = attendanceReviewPageFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('deficitReviews', fn ($reviews) => $reviews->count() === 1)
        ->assertSee('Excepciones de asistencia')
        ->assertSee('La marca observada no se modifica')
        ->assertSee('María Guardia')
        ->assertSee('06:15 → 14:00')
        ->assertSee('Llegada tardía')
        ->assertSee('06:00 → 06:15')
        ->assertSee('15 min · 0,25 h')
        ->assertSee('Sin excepción · se descuenta')
        ->assertSee('Conceder excepción')
        ->assertSee('Revocar excepción');
});

test('shows the current audited attendance exception and its reason', function () {
    $context = attendanceReviewPageFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $deficit = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->deficits
        ->sole();
    app(AttendanceExceptionRecorder::class)->decide(
        $context['period'],
        $context['employee'],
        '2026-07-20',
        $deficit->key,
        AttendanceException::GRANTED,
        'Demora autorizada por supervisión',
        $context['actor'],
    );
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertSee('Excepción concedida')
        ->assertSee('Demora autorizada por supervisión')
        ->assertSee($context['actor']->email);
});

test('grants the complete server-calculated attendance deficit with a mandatory reason', function () {
    $context = attendanceReviewPageFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $deficit = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->deficits
        ->sole();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call(
            'openAttendanceException',
            $context['employee']->id,
            '2026-07-20',
            $deficit->key,
            AttendanceException::GRANTED,
        )
        ->assertSet('showAttendanceExceptionModal', true)
        ->assertSet('attendanceDeficitSummary', '06:00 → 06:15 · 15 min')
        ->assertSee('Conceder excepción completa')
        ->assertSee('no puede modificarse parcialmente')
        ->set('attendanceExceptionReason', 'Ingreso autorizado por supervisión')
        ->call('saveAttendanceException')
        ->assertHasNoErrors()
        ->assertSet('showAttendanceExceptionModal', false)
        ->assertSee('Excepción concedida')
        ->assertSee('Ingreso autorizado por supervisión');

    $exception = AttendanceException::query()->sole();

    expect($exception->decision)->toBe(AttendanceException::GRANTED)
        ->and($exception->deficit_key)->toBe($deficit->key)
        ->and($exception->minutes)->toBe(15)
        ->and($exception->rate_minutes['ordinary'])->toBe(15)
        ->and($exception->decided_by)->toBe($context['actor']->id);
});

test('revokes a granted attendance exception without changing the deficit snapshot', function () {
    $context = attendanceReviewPageFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $deficit = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->deficits
        ->sole();
    $granted = app(AttendanceExceptionRecorder::class)->decide(
        $context['period'],
        $context['employee'],
        '2026-07-20',
        $deficit->key,
        AttendanceException::GRANTED,
        'Demora autorizada',
        $context['actor'],
    );
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call(
            'openAttendanceException',
            $context['employee']->id,
            '2026-07-20',
            $deficit->key,
            AttendanceException::REVOKED,
        )
        ->assertSet('showAttendanceExceptionModal', true)
        ->set('attendanceExceptionReason', 'La autorización fue anulada')
        ->call('saveAttendanceException')
        ->assertHasNoErrors()
        ->assertSee('Excepción revocada')
        ->assertSee('La autorización fue anulada');

    $current = AttendanceException::query()->current()->sole();

    expect(AttendanceException::query()->count())->toBe(2)
        ->and($current->decision)->toBe(AttendanceException::REVOKED)
        ->and($current->supersedes_id)->toBe($granted->id)
        ->and($current->deficit_key)->toBe($deficit->key)
        ->and($current->minutes)->toBe(15);
});

test('requires a reason and rejects an attendance deficit key that is not current', function () {
    $context = attendanceReviewPageFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $deficit = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->deficits
        ->sole();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call(
            'openAttendanceException',
            $context['employee']->id,
            '2026-07-20',
            $deficit->key,
            AttendanceException::GRANTED,
        )
        ->call('saveAttendanceException')
        ->assertHasErrors(['attendanceExceptionReason' => 'required'])
        ->call(
            'openAttendanceException',
            $context['employee']->id,
            '2026-07-20',
            str_repeat('0', 64),
            AttendanceException::GRANTED,
        )
        ->assertSet('showAttendanceExceptionModal', false)
        ->assertHasErrors('attendanceDeficitKey')
        ->call(
            'openAttendanceException',
            $context['employee']->id,
            '2026-07-20',
            $deficit->key,
            AttendanceException::REVOKED,
        )
        ->assertSet('showAttendanceExceptionModal', false)
        ->assertHasErrors('attendanceExceptionDecision')
        ->call(
            'openAttendanceException',
            $context['employee']->id,
            '2026-07-19',
            $deficit->key,
            AttendanceException::GRANTED,
        )
        ->assertSet('showAttendanceExceptionModal', false)
        ->assertHasErrors('attendanceDeficitKey');

    expect(AttendanceException::query()->count())->toBe(0);
});

test('does not allow attendance exceptions while the period is locked', function (string $status) {
    $context = attendanceReviewPageFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $deficit = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->deficits
        ->sole();
    $context['period']->update(['status' => $status]);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']->fresh()])
        ->call(
            'openAttendanceException',
            $context['employee']->id,
            '2026-07-20',
            $deficit->key,
            AttendanceException::GRANTED,
        )
        ->assertSet('showAttendanceExceptionModal', false)
        ->set('attendanceExceptionEmployeeId', $context['employee']->id)
        ->set('attendanceExceptionWorkDate', '2026-07-20')
        ->set('attendanceDeficitKey', $deficit->key)
        ->set('attendanceExceptionDecision', AttendanceException::GRANTED)
        ->set('attendanceExceptionReason', 'Intento fuera de estado')
        ->call('saveAttendanceException');

    expect(AttendanceException::query()->count())->toBe(0);
})->with(['processing', 'processed', 'approved', 'exported', 'cancelled']);

test('shows the current audited decision and its reason', function () {
    $context = attendanceReviewPageFixture();
    $candidate = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->overtimeCandidates
        ->sole();
    app(OvertimeDecisionRecorder::class)->decide(
        $context['period'],
        $context['employee'],
        '2026-07-20',
        $candidate->key,
        OvertimeDecision::REJECTED,
        'Tiempo de traslado hasta el reloj',
        $context['actor'],
    );
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertSee('Rechazado')
        ->assertSee('Tiempo de traslado hasta el reloj')
        ->assertSee($context['actor']->email);
});

test('approves the complete server-calculated candidate with a mandatory reason', function () {
    $context = attendanceReviewPageFixture();
    $candidate = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->overtimeCandidates
        ->sole();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call(
            'openOvertimeDecision',
            $context['employee']->id,
            '2026-07-20',
            $candidate->key,
            OvertimeDecision::APPROVED,
        )
        ->assertSet('showOvertimeDecisionModal', true)
        ->assertSet('overtimeCandidateSummary', '14:00 → 14:30 · 30 min')
        ->set('overtimeDecisionReason', 'Cobertura extraordinaria confirmada')
        ->call('saveOvertimeDecision')
        ->assertHasNoErrors()
        ->assertSet('showOvertimeDecisionModal', false)
        ->assertSee('Aprobado')
        ->assertSee('Cobertura extraordinaria confirmada');

    $decision = OvertimeDecision::query()->sole();

    expect($decision->decision)->toBe(OvertimeDecision::APPROVED)
        ->and($decision->candidate_key)->toBe($candidate->key)
        ->and($decision->minutes)->toBe(30)
        ->and($decision->rate_minutes['extra25'])->toBe(30)
        ->and($decision->decided_by)->toBe($context['actor']->id);
});

test('requires a reason and rejects a candidate key that is not current', function () {
    $context = attendanceReviewPageFixture();
    $candidate = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->overtimeCandidates
        ->sole();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call(
            'openOvertimeDecision',
            $context['employee']->id,
            '2026-07-20',
            $candidate->key,
            OvertimeDecision::REJECTED,
        )
        ->call('saveOvertimeDecision')
        ->assertHasErrors(['overtimeDecisionReason' => 'required'])
        ->call(
            'openOvertimeDecision',
            $context['employee']->id,
            '2026-07-20',
            str_repeat('0', 64),
            OvertimeDecision::APPROVED,
        )
        ->assertSet('showOvertimeDecisionModal', false)
        ->assertHasErrors('overtimeCandidateKey')
        ->call(
            'openOvertimeDecision',
            $context['employee']->id,
            '2026-07-19',
            $candidate->key,
            OvertimeDecision::APPROVED,
        )
        ->assertSet('showOvertimeDecisionModal', false)
        ->assertHasErrors('overtimeCandidateKey');

    expect(OvertimeDecision::query()->count())->toBe(0);
});

test('does not allow overtime decisions while the period is locked', function (string $status) {
    $context = attendanceReviewPageFixture();
    $candidate = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])
        ->sole()
        ->analysis
        ->overtimeCandidates
        ->sole();
    $context['period']->update(['status' => $status]);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']->fresh()])
        ->call(
            'openOvertimeDecision',
            $context['employee']->id,
            '2026-07-20',
            $candidate->key,
            OvertimeDecision::APPROVED,
        )
        ->assertSet('showOvertimeDecisionModal', false)
        ->set('overtimeDecisionEmployeeId', $context['employee']->id)
        ->set('overtimeDecisionWorkDate', '2026-07-20')
        ->set('overtimeCandidateKey', $candidate->key)
        ->set('overtimeDecision', OvertimeDecision::APPROVED)
        ->set('overtimeDecisionReason', 'Intento fuera de estado')
        ->call('saveOvertimeDecision');

    expect(OvertimeDecision::query()->count())->toBe(0);
})->with(['processing', 'processed', 'approved', 'exported', 'cancelled']);

function attendanceReviewPageFixture(
    string $entryAt = '2026-07-20 06:00:00',
    string $exitAt = '2026-07-20 14:30:00',
): array {
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

    foreach ([$entryAt, $exitAt] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);

    return compact('company', 'employee', 'period', 'actor');
}
