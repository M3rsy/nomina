<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\RawMarkMutationGuard;
use App\Services\CurrentCompany;
use App\Services\Payroll\AssignRawMarkEmployeeCommand;
use App\Services\Payroll\AuditedRawMarkRevision;
use App\Services\Payroll\MarkRawMarkCorrectedCommand;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('saveAssign assigns a single employee to a raw mark and corrects status', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $rawMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '12345',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openAssignModal', $rawMark->id)
        ->set('assignEmployeeId', $employee->id)
        ->call('saveAssign')
        ->assertHasErrors(['assignReason' => 'required']);

    expect($rawMark->fresh()->employee_id)->toBeNull();

    $component
        ->set('assignReason', 'Código verificado contra el legajo del empleado')
        ->call('saveAssign')
        ->assertHasNoErrors();

    $rawMark->refresh();

    expect($rawMark->employee_id)->toBe($employee->id)
        ->and($rawMark->status)->toBe('corrected')
        ->and($rawMark->metadata)->not->toBeNull();

    $revisions = $rawMark->metadata['revisions'] ?? [];
    expect($revisions)->toHaveCount(1);
    expect($revisions[0]['action'])->toBe('assign_employee');
    expect($revisions[0]['user_id'])->toBe($admin->id);
    expect($revisions[0]['reason'])->toBe('Código verificado contra el legajo del empleado');
    expect($revisions[0]['previous_employee_id'])->toBeNull();
    expect($revisions[0]['new_employee_id'])->toBe($employee->id);
    expect($revisions[0]['at'])->not->toBeEmpty();
});

test('audited revision assigns an employee and returns the affected mark count', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'validating']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $rawMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-42',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => '2026-01-05 08:00:00',
    ]);

    $result = app(AuditedRawMarkRevision::class)->assignEmployee(new AssignRawMarkEmployeeCommand(
        payPeriodId: $payPeriod->id,
        rawMarkId: $rawMark->id,
        employeeId: $employee->id,
        actorId: $admin->id,
        reason: 'Código verificado contra el legajo',
        assignAll: false,
    ));

    $revision = $rawMark->refresh()->metadata['revisions'][0];

    expect($result->affectedMarks)->toBe(1)
        ->and($rawMark->employee_id)->toBe($employee->id)
        ->and($rawMark->status)->toBe('corrected')
        ->and($revision['action'])->toBe('assign_employee')
        ->and($revision['user_id'])->toBe($admin->id)
        ->and($revision['reason'])->toBe('Código verificado contra el legajo')
        ->and($revision['previous_employee_id'])->toBeNull()
        ->and($revision['new_employee_id'])->toBe($employee->id)
        ->and($revision['at'])->not->toBeEmpty();
});

test('audited revision enforces actor permission and employee tenant', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'validating']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
        ->create(['employee_id' => null, 'status' => 'unknown_employee']);
    $outsider = Employee::factory()->forCompany($otherCompany)->create();
    $unprivileged = User::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $command = fn (int $actorId) => new AssignRawMarkEmployeeCommand(
        $period->id, $mark->id, $outsider->id, $actorId, 'Código verificado', false,
    );

    expect(fn () => app(AuditedRawMarkRevision::class)->assignEmployee($command($unprivileged->id)))
        ->toThrow(AuthorizationException::class);
    try {
        app(AuditedRawMarkRevision::class)->assignEmployee($command($admin->id));
        $this->fail('Expected the cross-company employee to fail closed.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('employee_id');
    }
    expect($mark->fresh()->employee_id)->toBeNull();
});

test('audited apply-all rolls back when a selected mark changes before locking', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'validating']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $marks = collect([8, 17])->map(fn (int $hour) => RawMark::factory()->forCompany($company)
        ->forPayPeriod($period)->forUploadedFile($file)->create([
            'employee_external_id' => 'CLOCK-RACE', 'employee_id' => null,
            'status' => 'unknown_employee', 'event_at' => "2026-01-05 {$hour}:00:00",
        ]));
    $changed = false;
    RawMark::retrieved(function (RawMark $mark) use ($marks, &$changed): void {
        if (! $changed && $mark->id === $marks[1]->id) {
            $changed = true;
            DB::table('raw_marks')->where('id', $mark->id)->update(['employee_external_id' => 'CLOCK-CHANGED']);
        }
    });

    try {
        app(AuditedRawMarkRevision::class)->assignEmployee(new AssignRawMarkEmployeeCommand(
            $period->id, $marks[0]->id, $employee->id, $admin->id, 'Código verificado', true,
        ));
        $this->fail('Expected the stale apply-all selection to fail closed.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('raw_mark');
    }

    expect($changed)->toBeTrue()
        ->and($marks->map->fresh()->pluck('employee_id')->filter())->toBeEmpty()
        ->and($marks[1]->fresh()->employee_external_id)->toBe('CLOCK-RACE')
        ->and(app(AttendanceFactGenerationTracker::class)->current($employee, '2026-01-05'))->toBe(0);
});

test('audited revision rejects a source moved after its authoritative read', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'validating']);
    $otherPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'validating']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
        ->create(['employee_id' => null, 'status' => 'unknown_employee']);
    $reads = 0;
    RawMark::retrieved(function (RawMark $retrieved) use ($mark, $otherPeriod, &$reads): void {
        if ($retrieved->id === $mark->id && ++$reads === 2) {
            DB::table('raw_marks')->where('id', $mark->id)->update(['pay_period_id' => $otherPeriod->id]);
        }
    });

    expect(fn () => app(AuditedRawMarkRevision::class)->assignEmployee(new AssignRawMarkEmployeeCommand(
        $period->id, $mark->id, $employee->id, $admin->id, 'Código verificado', false,
    )))->toThrow(ValidationException::class);

    expect($mark->fresh()->pay_period_id)->toBe($period->id)
        ->and($mark->employee_id)->toBeNull();
});

test('audited revision rejects a deleted reviewed period', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'validating']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)
        ->create(['status' => 'valid']);
    $period->delete();

    expect(fn () => app(AuditedRawMarkRevision::class)->markCorrected(new MarkRawMarkCorrectedCommand(
        $period->id, $mark->id, $admin->id, 'Revisión manual',
    )))->toThrow(ValidationException::class);

    expect($mark->fresh()->status)->toBe('valid');
});

test('assignApplyAll assigns employee to every raw mark with same external id and null employee', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $targetMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '99999',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);

    $secondTarget = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '99999',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-06 08:00:00'),
    ]);

    $differentExternal = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '11111',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-07 08:00:00'),
    ]);

    $alreadyAssigned = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '99999',
        'employee_id' => Employee::factory()->forCompany($company)->create()->id,
        'status' => 'valid',
        'event_at' => Carbon::parse('2026-01-08 08:00:00'),
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openAssignModal', $targetMark->id)
        ->set('assignEmployeeId', $employee->id)
        ->set('assignApplyAll', true)
        ->set('assignReason', 'Código externo verificado para todas las marcas')
        ->call('saveAssign')
        ->assertHasNoErrors();

    $targetMark->refresh();
    $secondTarget->refresh();
    $differentExternal->refresh();
    $alreadyAssigned->refresh();

    expect($targetMark->employee_id)->toBe($employee->id)
        ->and($targetMark->status)->toBe('corrected')
        ->and($secondTarget->employee_id)->toBe($employee->id)
        ->and($secondTarget->status)->toBe('corrected')
        ->and($differentExternal->employee_id)->toBeNull()
        ->and($alreadyAssigned->employee_id)->not->toBe($employee->id)
        ->and($alreadyAssigned->status)->toBe('valid');
});

test('batch assignment resolves once and mutates deduplicated marks in canonical order', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $marks = collect(['2026-01-05 08:00:00', '2026-01-05 17:00:00'])->map(
        fn (string $eventAt) => RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
            ->forUploadedFile($file)->create([
                'employee_id' => null,
                'status' => 'unknown_employee',
                'event_at' => $eventAt,
            ]),
    );
    $resolutions = 0;
    $mutatedIds = [];
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(RawMarkMutationGuard::class)->mutateBatch(
        $company->id,
        function () use ($marks, &$resolutions): array {
            $resolutions++;

            return [$marks[1]->id, $marks[0]->id, $marks[1]->id];
        },
        function (RawMark $mark) use ($employee, &$mutatedIds): void {
            $mutatedIds[] = $mark->id;
            $mark->update(['employee_id' => $employee->id, 'status' => 'corrected']);
        },
        targetEmployee: $employee,
    );

    $generationAttempts = collect(DB::getQueryLog())->filter(
        fn (array $query): bool => str_starts_with(
            $query['query'],
            'insert or ignore into "attendance_fact_generations"',
        ),
    )->count();
    DB::disableQueryLog();

    expect($resolutions)->toBe(1)
        ->and($mutatedIds)->toBe($marks->pluck('id')->sort()->values()->all())
        ->and($generationAttempts)->toBe(1)
        ->and($marks->map->fresh()->pluck('employee_id')->all())->toBe([$employee->id, $employee->id])
        ->and(app(AttendanceFactGenerationTracker::class)->current($employee, '2026-01-05'))->toBe(2);
});

test('cannot assign employee from another company to raw mark', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($companyA)->create();
    $file = UploadedFile::factory()->forCompany($companyA)->forPayPeriod($payPeriod)->create();
    $employeeB = Employee::factory()->forCompany($companyB)->create();
    $adminA = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    $rawMark = RawMark::factory()->forCompany($companyA)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '12345',
        'employee_id' => null,
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);

    $this->actingAs($adminA);
    app(CurrentCompany::class)->set($companyA);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openAssignModal', $rawMark->id)
        ->set('assignEmployeeId', $employeeB->id)
        ->set('assignReason', 'Intento de asignación cruzada')
        ->call('saveAssign')
        ->assertHasErrors('assignEmployeeId');

    $rawMark->refresh();

    expect($rawMark->employee_id)->toBeNull()
        ->and($rawMark->status)->toBe('unknown_employee');
});
