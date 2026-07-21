<?php

use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceExceptionRecorder;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\PayrollRules;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('grants and revokes the complete server-calculated deficit', function () {
    $context = attendanceExceptionRecorderFixture();
    $recorder = app(AttendanceExceptionRecorder::class);

    $granted = $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['deficit_key'],
        AttendanceException::GRANTED, 'Demora operativa autorizada', $context['actor'],
    );

    expect($granted->segment_kind)->toBe('late_arrival')
        ->and($granted->starts_at->toDateTimeString())->toBe('2026-07-20 06:00:00')
        ->and($granted->ends_at->toDateTimeString())->toBe('2026-07-20 06:15:00')
        ->and($granted->minutes)->toBe(15)
        ->and($granted->rate_minutes)->toBe([
            'ordinary' => 15,
            'extra25' => 0,
            'extra50' => 0,
            'extra75' => 0,
            'extra100' => 0,
        ])
        ->and($granted->decider->is($context['actor']))->toBeTrue();

    expect(fn () => $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['deficit_key'],
        AttendanceException::GRANTED, 'La misma decisión repetida', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(AttendanceException::query()->count())->toBe(1);

    $revoked = $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['deficit_key'],
        AttendanceException::REVOKED, 'La demora finalmente se descuenta', $context['actor'],
    );

    expect($revoked->supersedes_id)->toBe($granted->id)
        ->and($granted->fresh()->decision)->toBe(AttendanceException::GRANTED)
        ->and(AttendanceException::current()->sole()->is($revoked))->toBeTrue()
        ->and(AttendanceException::query()->count())->toBe(2);
});

test('requires a meaningful exception state change and reason', function (string $decision, string $reason) {
    $context = attendanceExceptionRecorderFixture();

    expect(fn () => app(AttendanceExceptionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['deficit_key'],
        $decision, $reason, $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(AttendanceException::query()->count())->toBe(0);
})->with([
    'unsupported decision' => ['pending', 'Pendiente'],
    'blank reason' => [AttendanceException::GRANTED, '   '],
    'revocation without grant' => [AttendanceException::REVOKED, 'Sin excepción previa'],
]);

test('rejects stale deficit keys after an observed mark changes', function () {
    $context = attendanceExceptionRecorderFixture();
    $context['entry_mark']->update([
        'event_at' => '2026-07-20 06:30:00',
        'status' => 'corrected',
    ]);
    $recorder = app(AttendanceExceptionRecorder::class);

    expect(fn () => $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['deficit_key'],
        AttendanceException::GRANTED, 'Clave anterior', $context['actor'],
    ))->toThrow(ValidationException::class);

    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($context['employee'], '2026-07-20');
    $deficit = app(AttendanceShiftAnalyzer::class)->analyze($occurrence)->deficits->sole();
    $exception = $recorder->decide(
        $context['period'], $context['employee'], '2026-07-20', $deficit->key,
        AttendanceException::GRANTED, 'Marca corregida y excepción confirmada', $context['actor'],
    );

    expect($deficit->key)->not->toBe($context['deficit_key'])
        ->and($exception->minutes)->toBe(30)
        ->and($exception->rate_minutes['ordinary'])->toBe(30);
});

test('requires an active marks manager from the same company', function () {
    $context = attendanceExceptionRecorderFixture();
    $otherCompany = Company::factory()->create();
    $actors = [
        User::factory()->forCompany($context['company'])->create(),
        User::factory()->forCompany($otherCompany)->create()->assignRole('company_admin'),
        User::factory()->forCompany($context['company'])->inactive()->create()->assignRole('company_admin'),
    ];

    foreach ($actors as $actor) {
        expect(fn () => app(AttendanceExceptionRecorder::class)->decide(
            $context['period'], $context['employee'], '2026-07-20', $context['deficit_key'],
            AttendanceException::GRANTED, 'Intento no autorizado', $actor,
        ))->toThrow(AuthorizationException::class);
    }

    expect(AttendanceException::query()->count())->toBe(0);
});

test('blocks exceptions while the payroll period is locked', function (string $status) {
    $context = attendanceExceptionRecorderFixture(periodStatus: $status);

    expect(fn () => app(AttendanceExceptionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $context['deficit_key'],
        AttendanceException::GRANTED, 'Intento sobre período bloqueado', $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(AttendanceException::query()->count())->toBe(0);
})->with(['processing', 'processed', 'approved', 'exported', 'cancelled']);

function attendanceExceptionRecorderFixture(string $periodStatus = 'uploaded'): array
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
    $marks = collect(['2026-07-20 06:15:00', '2026-07-20 14:00:00'])->map(
        fn (string $eventAt) => RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]),
    );
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');
    $holiday = app(PayrollRules::class)->isHoliday($company, $occurrence->workDate);
    $deficit = app(AttendanceShiftAnalyzer::class)->analyze($occurrence, $holiday)->deficits->sole();

    return compact('company', 'period', 'employee', 'actor') + [
        'deficit_key' => $deficit->key,
        'entry_mark' => $marks->first(),
    ];
}
