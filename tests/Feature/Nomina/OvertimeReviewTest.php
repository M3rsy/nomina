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
        ->assertSee('Pendiente de decisión');
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
