<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\ManualRawMarkRecorder;
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Attendance\ShiftOccurrenceResolver;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('completes an observed incomplete pair with one audited manual fact', function () {
    $context = manualMarkContext();
    $recorder = app(ManualRawMarkRecorder::class);
    $factGenerations = app(AttendanceFactGenerationTracker::class);

    $entry = observedMark($context, '2026-07-20 06:00:00');
    expect($factGenerations->current($context['employee'], '2026-07-20'))->toBe(0);

    $exit = $recorder->record(
        $context['period'], $context['employee'], '2026-07-20', '2026-07-20 14:00:00',
        'El reloj no registró la salida', $context['actor'],
    );
    $resolved = app(ShiftOccurrenceResolver::class)->resolve($context['employee'], '2026-07-20');

    expect($resolved->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($resolved->factGeneration)->toBe(1)
        ->and($resolved->marks->pluck('id')->all())->toBe([$entry->id, $exit->id])
        ->and($exit->source)->toBe(RawMark::SOURCE_MANUAL)
        ->and($exit->uploaded_file_id)->toBeNull()
        ->and($exit->raw_line)->toBeNull()
        ->and($exit->row_number)->toBeNull()
        ->and($exit->status)->toBe('corrected')
        ->and($exit->metadata['revisions'][0])->toMatchArray([
            'action' => 'manual_create',
            'user_id' => $context['actor']->id,
            'work_date' => '2026-07-20',
            'event_at' => '2026-07-20 14:00:00',
            'reason' => 'El reloj no registró la salida',
        ]);
});

test('rejects constructing a shift from manual facts without an observed mark', function () {
    $context = manualMarkContext();

    expect(fn () => app(ManualRawMarkRecorder::class)->record(
        $context['period'], $context['employee'], '2026-07-20', '2026-07-20 06:00:00',
        'Entrada informada por supervisión', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(RawMark::withoutCompanyScope()->count())->toBe(0);
});

test('records an overnight exit after the payroll period end for its starting work date', function () {
    $context = manualMarkContext();
    $context['period']->update(['end_date' => '2026-07-20']);
    WorkSchedule::where('work_schedule_profile_id', $context['profile']->id)->update([
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    $recorder = app(ManualRawMarkRecorder::class);

    observedMark($context, '2026-07-20 18:00:00');
    $exit = $recorder->record(
        $context['period'], $context['employee'], '2026-07-20', '2026-07-21 06:00:00',
        'Salida informada por supervisión', $context['actor'],
    );
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($context['employee'], '2026-07-20');

    expect($occurrence->status)->toBe(ShiftOccurrence::RESOLVED)
        ->and($occurrence->exitMark()->is($exit))->toBeTrue()
        ->and($exit->pay_period_id)->toBe($context['period']->id)
        ->and($exit->event_at->toDateTimeString())->toBe('2026-07-21 06:00:00');
});

test('rejects duplicate misplaced and ambiguity-producing manual marks atomically', function () {
    $context = manualMarkContext();
    $recorder = app(ManualRawMarkRecorder::class);
    $record = fn (string $eventAt) => $recorder->record(
        $context['period'], $context['employee'], '2026-07-20', $eventAt,
        'Reconstrucción validada', $context['actor'],
    );

    observedMark($context, '2026-07-20 06:00:00');

    expect(fn () => $record('2026-07-20 06:00:00'))->toThrow(ValidationException::class)
        ->and(fn () => $record('2026-07-21 06:00:00'))->toThrow(ValidationException::class)
        ->and(RawMark::withoutCompanyScope()->count())->toBe(1);

    $record('2026-07-20 14:00:00');

    expect(fn () => $record('2026-07-20 15:00:00'))->toThrow(ValidationException::class)
        ->and(RawMark::withoutCompanyScope()->count())->toBe(2);
});

test('requires valid context and an active marks manager from the same company', function () {
    $context = manualMarkContext();
    $recorder = app(ManualRawMarkRecorder::class);
    $record = fn (Employee $employee, string $workDate, string $reason, User $actor) => $recorder->record(
        $context['period'], $employee, $workDate, '2026-07-20 06:00:00', $reason, $actor,
    );
    $foreignEmployee = Employee::factory()->create();
    $withoutPermission = User::factory()->forCompany($context['company'])->create();
    $foreignManager = User::factory()->forCompany(Company::factory()->create())->create()->assignRole('company_admin');
    $inactiveManager = User::factory()->forCompany($context['company'])->inactive()->create()->assignRole('company_admin');

    expect(fn () => $record($context['employee'], '2026-07-20', '   ', $context['actor']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $record($foreignEmployee, '2026-07-20', 'Empleado ajeno', $context['actor']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $record($context['employee'], '2026-08-01', 'Fecha ajena', $context['actor']))
        ->toThrow(ValidationException::class);

    foreach ([$withoutPermission, $foreignManager, $inactiveManager] as $actor) {
        expect(fn () => $record($context['employee'], '2026-07-20', 'Intento no autorizado', $actor))
            ->toThrow(AuthorizationException::class);
    }

    expect(RawMark::withoutCompanyScope()->count())->toBe(0);
});

test('allows a super administrator to record for the selected company', function () {
    $context = manualMarkContext();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');
    observedMark($context, '2026-07-20 14:00:00');

    $mark = app(ManualRawMarkRecorder::class)->record(
        $context['period'], $context['employee'], '2026-07-20', '2026-07-20 06:00:00',
        'Parte confirmado', $superAdmin,
    );

    expect($mark->company_id)->toBe($context['company']->id)
        ->and($mark->metadata['revisions'][0]['user_id'])->toBe($superAdmin->id);
});

test('requires an assigned schedule for the selected work date', function () {
    $context = manualMarkContext();
    $unassigned = Employee::factory()->forCompany($context['company'])->create();
    $recorder = app(ManualRawMarkRecorder::class);

    expect(fn () => $recorder->record(
        $context['period'], $unassigned, '2026-07-20', '2026-07-20 06:00:00',
        'Sin asignación', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(fn () => $recorder->record(
            $context['period'], $context['employee'], '2026-07-21', '2026-07-21 06:00:00',
            'Día sin definición', $context['actor'],
        ))->toThrow(ValidationException::class)
        ->and(RawMark::withoutCompanyScope()->count())->toBe(0);
});

test('blocks manual marks while payroll is immutable', function (string $status) {
    $context = manualMarkContext($status);

    expect(fn () => app(ManualRawMarkRecorder::class)->record(
        $context['period'], $context['employee'], '2026-07-20', '2026-07-20 06:00:00',
        'Intento sobre período bloqueado', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(RawMark::withoutCompanyScope()->count())->toBe(0);
})->with(['processing', 'processed', 'approved', 'exported', 'cancelled']);

function manualMarkContext(string $status = 'validating'): array
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
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    return compact('company', 'period', 'employee', 'actor', 'profile');
}

function observedMark(array $context, string $eventAt): RawMark
{
    return RawMark::factory()
        ->forCompany($context['company'])
        ->forPayPeriod($context['period'])
        ->forEmployee($context['employee'])
        ->create([
            'employee_external_id' => $context['employee']->external_id,
            'event_at' => $eventAt,
            'status' => 'valid',
            'source' => 'glg',
        ]);
}
