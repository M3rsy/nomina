<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeCandidateReviewQuery;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('shows exact server-calculated overtime candidates beside observed and scheduled time', function () {
    $context = overtimeReviewPageFixture();
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

test('shows the current audited decision and its reason', function () {
    $context = overtimeReviewPageFixture();
    $candidate = app(OvertimeCandidateReviewQuery::class)
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
    $context = overtimeReviewPageFixture();
    $candidate = app(OvertimeCandidateReviewQuery::class)
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
    $context = overtimeReviewPageFixture();
    $candidate = app(OvertimeCandidateReviewQuery::class)
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
    $context = overtimeReviewPageFixture();
    $candidate = app(OvertimeCandidateReviewQuery::class)
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

function overtimeReviewPageFixture(): array
{
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

    foreach (['2026-07-20 06:00:00', '2026-07-20 14:30:00'] as $eventAt) {
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
