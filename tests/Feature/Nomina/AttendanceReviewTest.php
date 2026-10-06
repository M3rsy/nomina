<?php

use App\Livewire\Nomina\OvertimeReviewPanel;
use App\Livewire\Nomina\Revisar;
use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\AttendanceExceptionRecorder;
use App\Services\Attendance\AttendanceReviewQuery;
use App\Services\Attendance\AttendanceReviewSummaryReader;
use App\Services\Attendance\DuplicateRawMarkResolver;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\CurrentCompany;
use App\Services\Payroll\StartPayrollProcessing;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('attendance review fixtures create employees hired before fixed review periods', function () {
    fake()->seed(676);

    $context = attendanceReviewPageFixture();
    $addedEmployee = addAttendanceReviewEmployee($context, 'Ana', 'Ronda', 'SEG-102', ['2026-07-20']);

    expect($context['employee']->hired_at?->toDateString())->toBe('2026-07-01')
        ->and($addedEmployee->hired_at?->toDateString())->toBe('2026-07-20');
});

test('shows exact server-calculated overtime candidates beside attendance and scheduled time', function () {
    $context = attendanceReviewPageFixture();
    $this->actingAs($context['actor']);

    Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']])
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertViewHas('overtimeGroups', fn ($groups) => $groups->count() === 1)
        ->assertSee('Autorizaciones de horas extra')
        ->assertSee('El sistema calcula el tramo completo')
        ->assertSee('María Guardia')
        ->assertSee('Jornada asignada')
        ->assertSee('06:00 → 14:00')
        ->assertSee('Marcas de asistencia')
        ->assertDontSee('Marcas observadas')
        ->assertSee('06:00 → 14:30')
        ->assertSee('Salida posterior')
        ->assertSee('30 min · 0,50 h')
        ->assertSee('25%: 30 min')
        ->assertSee('Pendiente de decisión')
        ->assertSee('Aprobar completo')
        ->assertSee('Rechazar completo');
});

test('shows grouped duplicate rows with a kept and removable preview', function () {
    $context = attendanceReviewPageFixture();
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'duplicate',
        ]);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('duplicateSummary', fn (array $summary): bool => $summary['duplicate_groups'] === 1
            && $summary['duplicate_records_to_resolve'] === 1
            && $summary['employee_count_with_duplicates'] === 1
            && $summary['groups']->sole()['removable_records'][0]['row_number'] !== null)
        ->assertSee('Registros duplicados')
        ->assertSee('Conservar fila')
        ->assertSee('Resolver fila');
});

test('blocks readiness for every critical raw mark status and cannot be bypassed by confirmation', function (string $status) {
    $context = attendanceReviewPageFixture('2026-07-20 06:00:00', '2026-07-20 14:00:00');
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->create([
            'employee_external_id' => 'INC-'.$status,
            'employee_id' => $status === 'unknown_employee' ? null : $context['employee']->id,
            'event_at' => '2026-07-20 09:00:00',
            'status' => $status,
        ]);
    $this->actingAs($context['actor']);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('continueToReady')
        ->assertSet('showReadyConfirm', false)
        ->assertSet('readyMessage', fn (?string $message): bool => str_contains($message ?? '', 'No puede continuar'))
        ->assertViewHas('criticalReadiness', fn (array $readiness): bool => $readiness['has_blockers']);

    $component
        ->set('showReadyConfirm', true)
        ->call('confirmContinueToReady')
        ->assertSet('showReadyConfirm', false);

    expect(PayrollRun::query()->where('pay_period_id', $context['period']->id)->count())->toBe(0);
})->with(['pending', 'unknown_employee', 'out_of_period', 'invalid']);

test('blocks readiness when an unresolved duplicate group remains', function () {
    $context = attendanceReviewPageFixture('2026-07-20 06:00:00', '2026-07-20 14:00:00');
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'duplicate',
        ]);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('continueToReady')
        ->assertSet('showReadyConfirm', false)
        ->assertSet('readyMessage', fn (?string $message): bool => str_contains($message ?? '', 'grupos duplicados sin resolver'))
        ->assertViewHas('criticalReadiness', fn (array $readiness): bool => collect($readiness['incidents'])
            ->contains(fn (string $incident): bool => str_contains($incident, 'grupos duplicados sin resolver')));
});

test('shows duplicate groups from the filtered upload even when the kept mark is in another file', function () {
    $context = attendanceReviewPageFixture();
    $secondFile = UploadedFile::factory()->forCompany($context['company'])->forPayPeriod($context['period'])->create();
    RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($secondFile)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'duplicate',
        ]);
    $this->actingAs($context['actor']);

    Livewire::withQueryParams(['uploaded_file_id' => $secondFile->id])
        ->test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('duplicateSummary', fn (array $summary): bool => $summary['duplicate_groups'] === 1
            && $summary['duplicate_records_to_resolve'] === 1
            && $summary['groups']->sole()['kept_candidate']['file_name'] !== $secondFile->original_name)
        ->assertSee('Registros duplicados')
        ->assertSee('Resolver fila');
});

test('resolving an all-duplicate group promotes the kept mark to corrected', function () {
    $context = attendanceReviewPageFixture();
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'duplicate',
        ]);
    RawMark::query()->where('pay_period_id', $context['period']->id)
        ->where('event_at', '2026-07-20 06:00:00')
        ->update(['status' => 'duplicate']);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('openDuplicateResolution', $context['employee']->external_id.'|2026-07-20 06:00:00')
        ->set('duplicateResolutionReason', 'Se conserva una marca verificada')
        ->call('confirmDuplicateResolution')
        ->assertHasNoErrors();

    $activeMarks = RawMark::query()
        ->where('pay_period_id', $context['period']->id)
        ->where('employee_external_id', $context['employee']->external_id)
        ->where('event_at', '2026-07-20 06:00:00')
        ->where('status', '!=', 'deleted')
        ->get();

    expect($activeMarks)->toHaveCount(1)
        ->and($activeMarks->sole()->status)->toBe('corrected')
        ->and($activeMarks->sole()->metadata['revisions'][0]['action'])->toBe('keep_duplicate_as_corrected');
});

test('refuses to resolve a duplicate group containing a critical non-duplicate mark', function () {
    $context = attendanceReviewPageFixture();
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    $kept = RawMark::query()
        ->where('pay_period_id', $context['period']->id)
        ->where('event_at', '2026-07-20 06:00:00')
        ->where('status', 'valid')
        ->sole();
    $duplicate = RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'duplicate',
        ]);
    $critical = RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'pending',
        ]);

    expect(fn () => app(DuplicateRawMarkResolver::class)->resolve(
        $context['period'],
        $context['employee']->external_id.'|2026-07-20 06:00:00',
        $kept->id,
        'No debe ocultar una marca crítica',
        $context['actor']->id,
        $file->id,
    ))->toThrow(ValidationException::class);

    expect($kept->fresh()->status)->toBe('valid')
        ->and($duplicate->fresh()->status)->toBe('duplicate')
        ->and($critical->fresh()->status)->toBe('pending');
});

test('refuses to promote an all-duplicate group containing an unassigned mark', function () {
    $context = attendanceReviewPageFixture();
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    $kept = RawMark::query()
        ->where('pay_period_id', $context['period']->id)
        ->where('event_at', '2026-07-20 06:00:00')
        ->where('status', 'valid')
        ->sole();
    $kept->update(['status' => 'duplicate']);
    $unassigned = RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->create([
            'employee_id' => null,
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'duplicate',
        ]);

    expect(fn () => app(DuplicateRawMarkResolver::class)->resolve(
        $context['period'],
        $context['employee']->external_id.'|2026-07-20 06:00:00',
        $kept->id,
        'No debe promover marcas sin empleado',
        $context['actor']->id,
        $file->id,
    ))->toThrow(ValidationException::class);

    expect($kept->fresh()->status)->toBe('duplicate')
        ->and($unassigned->fresh()->status)->toBe('duplicate');
});

test('rechecks readiness when a ready period is started directly', function () {
    $context = attendanceReviewPageFixture();
    $context['period']->update(['status' => 'ready']);

    expect(fn () => app(StartPayrollProcessing::class)->start(
        $context['period']->fresh(),
        $context['actor'],
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class, 'El período todavía tiene revisiones obligatorias pendientes.');

    expect(PayrollRun::query()->where('pay_period_id', $context['period']->id)->count())->toBe(0);
});

test('resolving a cross-period duplicate removes only the current period duplicate', function () {
    $context = attendanceReviewPageFixture();
    $currentFile = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    $previousPeriod = PayPeriod::factory()->forCompany($context['company'])->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-19',
        'status' => 'uploaded',
    ]);
    $previousFile = UploadedFile::factory()->forCompany($context['company'])->forPayPeriod($previousPeriod)->create();
    $kept = RawMark::factory()->forCompany($context['company'])->forPayPeriod($previousPeriod)
        ->forUploadedFile($previousFile)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 09:00:00',
            'status' => 'valid',
        ]);
    $duplicate = RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($currentFile)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 09:00:00',
            'status' => 'duplicate',
        ]);
    $this->actingAs($context['actor']);

    Livewire::withQueryParams(['uploaded_file_id' => $currentFile->id])
        ->test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('openDuplicateResolution', $context['employee']->external_id.'|2026-07-20 09:00:00')
        ->assertSet('showDuplicateResolutionModal', true)
        ->set('duplicateResolutionReason', 'Ya existía una marca válida en otro período')
        ->call('confirmDuplicateResolution')
        ->assertHasNoErrors();

    expect($kept->fresh()->status)->toBe('valid')
        ->and($duplicate->fresh()->status)->toBe('deleted');
});

test('resolving one duplicate group keeps one active record and audits deleted extras', function () {
    $context = attendanceReviewPageFixture();
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    $duplicate = RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->forEmployee($context['employee'])->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'duplicate',
        ]);
    $this->actingAs($context['actor']);
    $key = $context['employee']->external_id.'|2026-07-20 06:00:00';

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('openDuplicateResolution', $key)
        ->assertSet('showDuplicateResolutionModal', true)
        ->assertSee('Se conserva')
        ->assertSee('Pasan a eliminados')
        ->set('duplicateResolutionReason', 'Se verificó la fila original del reloj')
        ->call('confirmDuplicateResolution')
        ->assertHasNoErrors()
        ->assertSet('showDuplicateResolutionModal', false);

    expect(RawMark::query()->where('pay_period_id', $context['period']->id)
        ->where('employee_external_id', $context['employee']->external_id)
        ->where('event_at', '2026-07-20 06:00:00')
        ->where('status', '!=', 'deleted')->count())->toBe(1)
        ->and($duplicate->fresh()->status)->toBe('deleted')
        ->and($duplicate->fresh()->metadata['revisions'][0]['action'])->toBe('resolve_duplicate')
        ->and($duplicate->fresh()->metadata['revisions'][0]['kept_raw_mark_id'])->not->toBe($duplicate->id);
});

test('bulk duplicate resolution keeps one active record per group', function () {
    $context = attendanceReviewPageFixture();
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    foreach (['2026-07-20 06:00:00', '2026-07-20 14:30:00'] as $eventAt) {
        RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
            ->forUploadedFile($file)->forEmployee($context['employee'])->create([
                'employee_external_id' => $context['employee']->external_id,
                'event_at' => $eventAt,
                'status' => 'duplicate',
            ]);
    }
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('openBulkDuplicateResolution')
        ->assertSet('showDuplicateResolutionModal', true)
        ->assertSet('duplicateResolutionBulk', true)
        ->set('duplicateResolutionReason', 'Se conservaron las primeras filas verificadas')
        ->call('confirmDuplicateResolution')
        ->assertHasNoErrors();

    foreach (['2026-07-20 06:00:00', '2026-07-20 14:30:00'] as $eventAt) {
        expect(RawMark::query()->where('pay_period_id', $context['period']->id)
            ->where('employee_external_id', $context['employee']->external_id)
            ->where('event_at', $eventAt)
            ->where('status', '!=', 'deleted')->count())->toBe(1);
    }
});

test('groups the current overtime page by employee in collapsed sections', function () {
    $context = attendanceReviewPageFixture();
    addAttendanceReviewEmployee($context, 'Ana', 'Ronda', 'SEG-102', ['2026-07-20']);
    $this->actingAs($context['actor']);
    Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']])
        ->assertViewHas('overtimeGroups', fn ($groups) => $groups->count() === 2
            && $groups->every(fn ($group) => $group['rows']->count() === 1))
        ->assertSee('María Guardia')
        ->assertSee('Ana Ronda')
        ->assertSeeHtml('<details');
});

test('bounds overtime candidates to 25 rows and paginates one employee', function () {
    $context = attendanceReviewPageFixture();
    addAttendanceReviewDates($context, collect(range(1, 26))->map(
        fn (int $day) => '2026-07-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
    )->all());
    $this->actingAs($context['actor']);
    $component = Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']->fresh()])
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 26
            && $rows->count() === 25
            && $rows->currentPage() === 1)
        ->assertViewHas('overtimeGroups', fn ($groups) => $groups->count() === 1
            && $groups->sole()['rows']->count() === 25);

    $component
        ->call('setPage', 2, 'overtimePage')
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 26
            && $rows->count() === 1
            && $rows->currentPage() === 2)
        ->assertViewHas('overtimeGroups', fn ($groups) => $groups->sole()['rows']->count() === 1);
});

test('filters overtime candidates and resets only their paginator', function () {
    $context = attendanceReviewPageFixture();
    addAttendanceReviewDates($context, ['2026-07-20', '2026-07-21']);
    addAttendanceReviewEmployee($context, 'Ana', 'Ronda', 'SEG-102', ['2026-07-20'], '19:00:00');
    $candidate = app(AttendanceReviewQuery::class)->forPeriod($context['period']->fresh())
        ->first(fn ($review) => $review->employee->is($context['employee'])
            && $review->analysis->workDate->toDateString() === '2026-07-20')
        ->analysis->overtimeCandidates->sole();
    app(OvertimeDecisionRecorder::class)->decide(
        $context['period']->fresh(),
        $context['employee'],
        '2026-07-20',
        $candidate->key,
        OvertimeDecision::REJECTED,
        'Caso auditado',
        $context['actor'],
    );
    $this->actingAs($context['actor']);
    $component = Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']->fresh()])
        ->assertSee('Filtrar autorizaciones')
        ->set('overtimeSearch', 'ana')
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertViewHas('overtimeGroups', fn ($groups) => $groups->pluck('employee.full_name')->all() === ['Ana Ronda'])
        ->assertSee('Ana Ronda')
        ->set('overtimeSearch', '')
        ->set('overtimeDate', '2026-07-21')
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertSee('Fecha laboral 21/07/2026')
        ->set('overtimeDate', '')
        ->set('overtimeRate', 'extra50')
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertSee('50%: 60 min')
        ->set('overtimeRate', '')
        ->set('overtimeStatus', OvertimeDecision::REJECTED)
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertSee('Caso auditado');

    $component
        ->call('setPage', 2)
        ->call('setPage', 2, 'overtimePage')
        ->set('overtimeSearch', 'María')
        ->assertSet('paginators.page', 2)
        ->assertSet('paginators.overtimePage', 1);
});

test('normalizes invalid overtime filters from the URL', function () {
    $context = attendanceReviewPageFixture();
    $this->actingAs($context['actor']);
    Livewire::withQueryParams([
        'overtimeStatus' => 'invalid',
        'overtimeDate' => '20-07-2026',
        'overtimeRate' => 'extra500',
    ])->test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']])
        ->assertSet('overtimeStatus', 'pending')
        ->assertSet('overtimeDate', '')
        ->assertSet('overtimeRate', '')
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1);
});

test('shows exact attendance deficits without changing attendance marks', function () {
    $context = attendanceReviewPageFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('deficitReviews', fn ($reviews) => $reviews->count() === 1)
        ->assertSee('Excepciones de asistencia')
        ->assertSee('Las marcas de asistencia no se modifican')
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

test('defaults the overtime inbox to pending candidates and can reveal rejected decisions', function () {
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

    $component = Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']])
        ->assertSet('overtimeStatus', 'pending')
        ->assertDontSee('Tiempo de traslado hasta el reloj');

    $component
        ->set('overtimeStatus', OvertimeDecision::REJECTED)
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

    Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']])
        ->call('openOvertimeDecision', $context['employee']->id, '2026-07-20', $candidate->key,
            OvertimeDecision::APPROVED, '14:00 → 14:30 · 30 min', '2026-07-20T14:00', '2026-07-20T14:30')
        ->assertSet('showOvertimeDecisionModal', true)
        ->assertSet('overtimeCandidateSummary', '14:00 → 14:30 · 30 min')
        ->set('overtimeDecisionReason', 'Cobertura extraordinaria confirmada')
        ->call('submitOvertimeDecision')
        ->assertHasNoErrors()
        ->assertDispatched('overtime-decision-submitted');

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('saveOvertimeDecisionFromPanel', [
            'overtimeDecisionEmployeeId' => $context['employee']->id,
            'overtimeDecisionWorkDate' => '2026-07-20',
            'overtimeCandidateKey' => $candidate->key,
            'overtimeDecision' => OvertimeDecision::APPROVED,
            'overtimeDecisionReason' => 'Cobertura extraordinaria confirmada',
            'overtimeApprovedStartsAt' => '2026-07-20T14:00',
            'overtimeApprovedEndsAt' => '2026-07-20T14:30',
        ])
        ->assertHasNoErrors()
        ->assertDispatched('overtime-decision-recorded');

    Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']])
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 0)
        ->set('overtimeStatus', OvertimeDecision::APPROVED)
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

    Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']])
        ->call('openOvertimeDecision', $context['employee']->id, '2026-07-20', $candidate->key,
            OvertimeDecision::REJECTED, '14:00 → 14:30 · 30 min', '2026-07-20T14:00', '2026-07-20T14:30')
        ->call('submitOvertimeDecision')
        ->assertHasErrors(['overtimeDecisionReason' => 'required'])
        ->assertSet('showOvertimeDecisionModal', true);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->call('saveOvertimeDecisionFromPanel', [
            'overtimeDecisionEmployeeId' => $context['employee']->id,
            'overtimeDecisionWorkDate' => '2026-07-20',
            'overtimeCandidateKey' => str_repeat('0', 64),
            'overtimeDecision' => OvertimeDecision::APPROVED,
            'overtimeDecisionReason' => 'Cobertura confirmada',
            'overtimeApprovedStartsAt' => '2026-07-20T14:00',
            'overtimeApprovedEndsAt' => '2026-07-20T14:30',
        ])
        ->assertHasErrors('overtimeCandidateKey')
        ->call('saveOvertimeDecisionFromPanel', [
            'overtimeDecisionEmployeeId' => $context['employee']->id,
            'overtimeDecisionWorkDate' => '2026-07-19',
            'overtimeCandidateKey' => $candidate->key,
            'overtimeDecision' => OvertimeDecision::APPROVED,
            'overtimeDecisionReason' => 'Cobertura confirmada',
            'overtimeApprovedStartsAt' => '2026-07-20T14:00',
            'overtimeApprovedEndsAt' => '2026-07-20T14:30',
        ])
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
        ->call('saveOvertimeDecisionFromPanel', [
            'overtimeDecisionEmployeeId' => $context['employee']->id,
            'overtimeDecisionWorkDate' => '2026-07-20',
            'overtimeCandidateKey' => $candidate->key,
            'overtimeDecision' => OvertimeDecision::APPROVED,
            'overtimeDecisionReason' => 'Intento fuera de estado',
            'overtimeApprovedStartsAt' => '2026-07-20T14:00',
            'overtimeApprovedEndsAt' => '2026-07-20T14:30',
        ]);

    expect(OvertimeDecision::query()->count())->toBe(0);
})->with(['processing', 'processed', 'approved', 'exported', 'cancelled']);

test('exposes employee attendance summaries with detected overtime', function () {
    $context = attendanceReviewPageFixture();
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('attendanceSummary', fn ($summaries): bool => $summaries->count() === 1
            && $summaries->sole()['employee_id'] === $context['employee']->id
            && $summaries->sole()['worked_minutes'] === 510
            && $summaries->sole()['required_minutes'] === 480
            && $summaries->sole()['overtime_minutes'] === 30
            && $summaries->sole()['status'] === 'overtime')
        ->assertSee('Jornada laboral')
        ->assertSee('Horas extra')
        ->assertSee('María Guardia');
});

test('exposes employee shortfall in the attendance summary', function () {
    $context = attendanceReviewPageFixture('2026-07-20 06:15:00', '2026-07-20 14:00:00');
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('attendanceSummary', fn ($summaries): bool => $summaries->sole()['missing_minutes'] === 15
            && $summaries->sole()['status'] === 'shortfall')
        ->assertSee('Déficit');
});

test('uses 480 required minutes for duration-first Saturday no-mark and incomplete rows', function (bool $incomplete, int $missingMinutes) {
    $context = durationFirstSaturdayAttendanceReviewFixture($incomplete);
    $summary = app(AttendanceReviewSummaryReader::class)->forPeriod($context['period']);

    expect($summary->sole()['required_minutes'])->toBe(480)
        ->and($summary->sole()['missing_minutes'])->toBe($missingMinutes);
})->with([
    'no mark' => [false, 240],
    'incomplete pair' => [true, 0],
]);

test('identifies an incomplete attendance pair in the employee summary', function () {
    $context = attendanceReviewPageFixture();
    $incomplete = Employee::factory()->forCompany($context['company'])->create([
        'first_name' => 'Ana',
        'last_name' => 'Incompleta',
        'external_id' => 'SEG-102',
        'hired_at' => '2026-07-01',
    ]);
    app(EmployeeScheduleAssigner::class)->assign(
        $incomplete,
        WorkScheduleProfile::query()->where('company_id', $context['company']->id)->sole(),
        '2026-07-01',
        'Jornada diurna',
    );
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
        ->forUploadedFile($file)->forEmployee($incomplete)->create([
            'event_at' => '2026-07-20 06:00:00',
            'status' => 'valid',
        ]);
    $this->actingAs($context['actor']);

    Livewire::test(Revisar::class, ['payPeriod' => $context['period']])
        ->assertViewHas('attendanceSummary', fn ($summaries): bool => $summaries->firstWhere('employee_id', $incomplete->id)['status'] === 'incomplete'
            && $summaries->firstWhere('employee_id', $incomplete->id)['incomplete_count'] === 1)
        ->assertSee('Incompleta');
});

function durationFirstSaturdayAttendanceReviewFixture(bool $incomplete): array
{
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 6,
        'start_time' => '08:00',
        'end_time' => '12:00',
        'base_ordinary_hours' => 4,
        'is_working_day' => true,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'first_name' => 'Sábado',
        'last_name' => 'Revisión',
        'external_id' => 'SEG-SAT',
        'hired_at' => '2026-07-01',
    ]);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada sábado');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-18',
        'end_date' => '2026-07-18',
        'status' => 'uploaded',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    if ($incomplete) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => '2026-07-18 08:00:00',
                'status' => 'valid',
            ]);
    }

    WorkScheduleProfilePublication::withoutCompanyScope()
        ->where('profile_id', $profile->id)
        ->sole()
        ->update(['payroll_policy_key' => WorkScheduleProfilePublication::DURATION_FIRST_V2]);

    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);

    return compact('company', 'employee', 'period', 'actor');
}

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
        'hired_at' => '2026-07-01',
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
                'employee_external_id' => $employee->external_id,
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);

    return compact('company', 'employee', 'period', 'actor');
}

function addAttendanceReviewEmployee(
    array $context,
    string $firstName,
    string $lastName,
    string $externalId,
    array $dates,
    string $exitTime = '14:30:00',
): Employee {
    $employee = Employee::factory()->forCompany($context['company'])->create([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'external_id' => $externalId,
        'hired_at' => $context['period']->start_date,
    ]);
    app(EmployeeScheduleAssigner::class)->assign(
        $employee,
        WorkScheduleProfile::query()->where('company_id', $context['company']->id)->sole(),
        $context['period']->start_date,
        'Jornada diurna',
    );
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    foreach ($dates as $date) {
        foreach (['06:00:00', $exitTime] as $time) {
            RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
                ->forUploadedFile($file)->forEmployee($employee)->create([
                    'event_at' => "{$date} {$time}",
                    'status' => 'valid',
                ]);
        }
    }

    return $employee;
}

function addAttendanceReviewDates(array $context, array $dates): void
{
    $profile = WorkScheduleProfile::query()->where('company_id', $context['company']->id)->sole();
    foreach (range(0, 6) as $dayOfWeek) {
        WorkSchedule::query()->firstOrCreate([
            'company_id' => $context['company']->id,
            'work_schedule_profile_id' => $profile->id,
            'day_of_week' => $dayOfWeek,
        ], [
            'start_time' => '06:00',
            'end_time' => '14:00',
            'base_ordinary_hours' => 8,
            'is_working_day' => true,
        ]);
    }

    $context['period']->update([
        'start_date' => min($dates),
        'end_date' => max($dates),
    ]);
    $file = UploadedFile::query()->where('pay_period_id', $context['period']->id)->sole();
    foreach (array_diff($dates, ['2026-07-20']) as $date) {
        foreach (['06:00:00', '14:30:00'] as $time) {
            RawMark::factory()->forCompany($context['company'])->forPayPeriod($context['period'])
                ->forUploadedFile($file)->forEmployee($context['employee'])->create([
                    'event_at' => "{$date} {$time}",
                    'status' => 'valid',
                ]);
        }
    }
}
