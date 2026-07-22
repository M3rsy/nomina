<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Attendance\ShiftOccurrenceResolver;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('records an exact audited manual clock fact from payroll review', function () {
    $context = manualMarkReviewContext();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertSee('Agregar marca manual')
        ->call('openManualMarkModal', $context['employee']->id, '2026-07-20')
        ->assertSet('showManualMarkModal', true)
        ->assertSet('manualMarkEmployeeId', $context['employee']->id)
        ->assertSet('manualMarkWorkDate', '2026-07-20')
        ->assertSee('Registrar hecho faltante')
        ->assertSee('no modifica el archivo TXT/DAT')
        ->set('manualMarkEventAt', '2026-07-20T14:00:00')
        ->set('manualMarkReason', 'El reloj no registró la salida')
        ->call('saveManualMark')
        ->assertHasNoErrors()
        ->assertSet('showManualMarkModal', false)
        ->assertSee('Marca manual auditada')
        ->assertSee('Origen manual');

    $mark = RawMark::query()->where('source', RawMark::SOURCE_MANUAL)->sole();
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($context['employee'], '2026-07-20');

    expect($mark->event_at->toDateTimeString())->toBe('2026-07-20 14:00:00')
        ->and($mark->uploaded_file_id)->toBeNull()
        ->and($mark->raw_line)->toBeNull()
        ->and($mark->row_number)->toBeNull()
        ->and($mark->metadata['revisions'][0])->toMatchArray([
            'action' => 'manual_create',
            'user_id' => $context['actor']->id,
            'work_date' => '2026-07-20',
            'reason' => 'El reloj no registró la salida',
        ])
        ->and($occurrence->status)->toBe(ShiftOccurrence::RESOLVED);
});

test('requires a reason and rejects employees outside the payroll company', function () {
    $context = manualMarkReviewContext();
    $foreignEmployee = Employee::factory()->create();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('openManualMarkModal')
        ->set('manualMarkEmployeeId', $foreignEmployee->id)
        ->set('manualMarkWorkDate', '2026-07-20')
        ->set('manualMarkEventAt', '2026-07-20T14:00:00')
        ->set('manualMarkReason', 'Salida confirmada')
        ->call('saveManualMark')
        ->assertHasErrors('manualMarkEmployeeId')
        ->set('manualMarkEmployeeId', $context['employee']->id)
        ->set('manualMarkReason', '')
        ->call('saveManualMark')
        ->assertHasErrors('manualMarkReason');

    expect(RawMark::query()->where('source', RawMark::SOURCE_MANUAL)->count())->toBe(0);
});

test('shows recorder validation when the timestamp does not belong to the work date', function () {
    $context = manualMarkReviewContext();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('openManualMarkModal', $context['employee']->id, '2026-07-20')
        ->set('manualMarkEventAt', '2026-07-21T14:00:00')
        ->set('manualMarkReason', 'Intento fuera de jornada')
        ->call('saveManualMark')
        ->assertHasErrors('event_at')
        ->assertSet('showManualMarkModal', true);

    expect(RawMark::query()->where('source', RawMark::SOURCE_MANUAL)->count())->toBe(0);
});

test('blocks manual mark controls and direct mutations in immutable payroll states', function (string $status) {
    $context = manualMarkReviewContext($status);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('openManualMarkModal', $context['employee']->id, '2026-07-20')
        ->assertSet('showManualMarkModal', false)
        ->set('manualMarkEmployeeId', $context['employee']->id)
        ->set('manualMarkWorkDate', '2026-07-20')
        ->set('manualMarkEventAt', '2026-07-20T14:00:00')
        ->set('manualMarkReason', 'Intento bloqueado')
        ->call('saveManualMark');

    expect(RawMark::query()->where('source', RawMark::SOURCE_MANUAL)->count())->toBe(0);
})->with(['processing', 'processed', 'approved', 'exported', 'cancelled']);

test('rechecks a payroll state that becomes processing after the review mounted', function () {
    $context = manualMarkReviewContext();
    $this->actingAs($context['actor']);
    $component = Livewire::test(Revisar::class, ['payPeriod' => $context['period']]);
    $context['period']->update(['status' => 'processing']);

    $component
        ->set('manualMarkEmployeeId', $context['employee']->id)
        ->set('manualMarkWorkDate', '2026-07-20')
        ->set('manualMarkEventAt', '2026-07-20T14:00:00')
        ->set('manualMarkReason', 'Estado obsoleto en el navegador')
        ->call('saveManualMark');

    expect(RawMark::query()->where('source', RawMark::SOURCE_MANUAL)->count())->toBe(0);
});

function manualMarkReviewContext(string $status = 'validating'): array
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
        'status' => $status,
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->forEmployee($employee)->create([
        'event_at' => '2026-07-20 06:00:00',
        'status' => 'valid',
    ]);
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    return compact('company', 'period', 'employee', 'actor');
}
