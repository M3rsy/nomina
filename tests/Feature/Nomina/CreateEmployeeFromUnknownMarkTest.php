<?php

use App\Livewire\Nomina\Revisar;
use App\Models\AttendanceFactGeneration;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\CurrentCompany;
use App\Services\Payroll\AuditedRawMarkRevision;
use App\Services\Payroll\CreateEmployeeFromUnknownMarkCommand;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(PermissionRoleSeeder::class));

/** @param array<string, mixed> $overrides */
function unknownEmployeeCommand(RawMark $mark, WorkScheduleProfile $profile, User $actor, array $overrides = []): CreateEmployeeFromUnknownMarkCommand
{
    $data = [
        'paymentCode' => 'PAY-'.$mark->id,
        'firstName' => 'Ana',
        'lastName' => 'López',
        'dni' => '0801200000001',
        'jobTitle' => 'Analista',
        'hiredAt' => $mark->event_at->toDateString(),
        'reason' => 'Identidad verificada por Recursos Humanos.',
        'assignAll' => true,
        ...$overrides,
    ];

    return new CreateEmployeeFromUnknownMarkCommand(
        $mark->id, $profile->id, $actor->id,
        $data['paymentCode'], $data['firstName'], $data['lastName'], $data['dni'],
        $data['jobTitle'], $data['hiredAt'], $data['reason'], $data['assignAll'],
    );
}

test('keeps the existing payroll review workflow while delegating the write', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-LIVEWIRE',
        'event_at' => $period->start_date->addDay()->setTime(8, 0),
        'status' => 'unknown_employee',
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $period])
        ->call('openCreateEmployeeModal', $mark->id)
        ->set('createEmployeePaymentCode', 'PAY-LIVEWIRE')
        ->set('createEmployeeFirstName', 'Ana')
        ->set('createEmployeeLastName', 'López')
        ->set('createEmployeeDni', '0801-2000-00007')
        ->set('createEmployeeJobTitle', 'Analista')
        ->set('createEmployeeScheduleProfileId', $profile->id)
        ->set('createEmployeeReason', 'Identidad verificada desde nómina.');

    $profile->update(['retired_at' => now()]);
    $component->call('saveCreatedEmployee')
        ->assertHasErrors('createEmployeeScheduleProfileId');
    $profile->update(['retired_at' => null]);

    $component
        ->call('saveCreatedEmployee')
        ->assertHasNoErrors()
        ->assertSet('showCreateEmployeeModal', false);

    $employee = Employee::withoutCompanyScope()->where('external_id', 'CLOCK-LIVEWIRE')->sole();
    expect($mark->refresh()->employee_id)->toBe($employee->id)
        ->and($employee->scheduleAssignments()->count())->toBe(1);
});

test('shows refresh guidance when the source mark disappears', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create(['status' => 'unknown_employee']);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $period])
        ->call('openCreateEmployeeModal', $mark->id);
    DB::table('raw_marks')->where('id', $mark->id)->delete();

    $component->call('saveCreatedEmployee')
        ->assertHasErrors('createEmployeeRawMarkId');
});

test('creates and schedules an unknown employee while assigning every matching mark', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $marks = RawMark::factory()->count(2)->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-77',
        'event_at' => $period->start_date->addDay()->setTime(8, 0),
        'status' => 'unknown_employee',
        'metadata' => ['import_batch' => 'BATCH-1'],
    ]);
    $otherPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => $period->end_date->addDay()->toDateString(),
        'end_date' => $period->end_date->addDays(15)->toDateString(),
        'status' => 'draft',
    ]);
    $otherFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($otherPeriod)->create();
    $otherMark = RawMark::factory()->forCompany($company)->forPayPeriod($otherPeriod)->forUploadedFile($otherFile)->create([
        'employee_external_id' => 'CLOCK-77',
        'event_at' => $otherPeriod->start_date->addDay()->setTime(8, 0),
        'status' => 'unknown_employee',
    ]);
    $factGenerationTransactionLevels = [];
    $writeQueries = [];
    $harnessTransactionLevel = DB::transactionLevel();
    AttendanceFactGeneration::retrieved(function () use (&$factGenerationTransactionLevels): void {
        $factGenerationTransactionLevels[] = DB::transactionLevel();
    });
    DB::listen(function (QueryExecuted $query) use (&$writeQueries): void {
        $writeQueries[] = strtolower($query->sql);
    });

    $result = app(AuditedRawMarkRevision::class)->createEmployee(unknownEmployeeCommand(
        $marks->first(), $profile, $actor, ['paymentCode' => 'PAY-007', 'hiredAt' => $period->start_date->toDateString()],
    ));
    $employee = Employee::withoutCompanyScope()->findOrFail($result->employeeId);
    $assignedMark = $marks->first()->refresh();
    $assignment = $employee->scheduleAssignments()->sole();
    $employeeInsert = collect($writeQueries)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into "employees"'));
    $assignmentInsert = collect($writeQueries)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into "employee_schedule_assignments"'));
    $rawMarkLock = collect($writeQueries)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from "raw_marks"')
        && str_contains($sql, 'where "id" in') && str_contains($sql, 'order by "id" asc'));

    expect($employee->external_id)->toBe('CLOCK-77')
        ->and($result->assignedMarks)->toBe(2)
        ->and($employee->scheduleAssignments()->count())->toBe(1)
        ->and($assignment->assigned_by)->toBe($actor->id)
        ->and($assignment->reason)->toBe('Identidad verificada por Recursos Humanos.')
        ->and($assignedMark->metadata['import_batch'])->toBe('BATCH-1')
        ->and($assignedMark->metadata['revisions'][0])->toMatchArray([
            'action' => 'create_and_assign_employee',
            'user_id' => $actor->id,
            'reason' => 'Identidad verificada por Recursos Humanos.',
            'new_employee_id' => $employee->id,
        ])
        ->and($factGenerationTransactionLevels)->not->toBeEmpty()
        ->and($factGenerationTransactionLevels)->each->toBe($harnessTransactionLevel + 1)
        ->and($employeeInsert)->toBeInt()->toBeLessThan($assignmentInsert)
        ->and($assignmentInsert)->toBeLessThan($rawMarkLock)
        ->and($otherMark->refresh()->employee_id)->toBeNull()
        ->and($otherMark->status)->toBe('unknown_employee');
});

test('rolls back the employee and schedule when assigning a mark fails', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-ROLLBACK',
        'event_at' => $period->start_date->addDay()->setTime(8, 0),
        'source' => RawMark::SOURCE_MANUAL,
        'status' => 'unknown_employee',
    ]);

    expect(fn () => app(AuditedRawMarkRevision::class)->createEmployee(unknownEmployeeCommand(
        $mark, $profile, $actor, ['paymentCode' => 'PAY-ROLLBACK', 'assignAll' => false],
    )))->toThrow(ValidationException::class);

    expect(Employee::withoutCompanyScope()->where('external_id', 'CLOCK-ROLLBACK')->exists())->toBeFalse()
        ->and(EmployeeScheduleAssignment::withoutCompanyScope()->count())->toBe(0)
        ->and($mark->refresh()->employee_id)->toBeNull()
        ->and($mark->status)->toBe('unknown_employee')
        ->and($mark->metadata)->toBeNull();
});

test('rolls back when authoritative source routing changes before raw-mark locking', function (string $changedField) {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $alternatePeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => $period->end_date->addDay()->toDateString(),
        'end_date' => $period->end_date->addDays(15)->toDateString(),
        'status' => 'draft',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-ROUTED',
        'event_at' => $period->start_date->addDay()->setTime(8, 0),
        'status' => 'unknown_employee',
    ]);
    $originalEventAt = $mark->event_at->toDateTimeString();
    $raceTriggered = false;
    Employee::created(function (Employee $employee) use ($company, $mark, $alternatePeriod, $changedField, &$raceTriggered): void {
        if ($raceTriggered || $employee->company_id !== $company->id) {
            return;
        }

        $raceTriggered = true;
        $changes = match ($changedField) {
            'external_code' => ['employee_external_id' => 'CLOCK-CHANGED'],
            'pay_period' => ['pay_period_id' => $alternatePeriod->id],
            'event_time' => ['event_at' => $mark->event_at->addHour()->toDateTimeString()],
        };
        DB::table('raw_marks')->where('id', $mark->id)->update($changes);
    });

    try {
        app(AuditedRawMarkRevision::class)->createEmployee(unknownEmployeeCommand(
            $mark,
            $profile,
            $actor,
            ['paymentCode' => 'PAY-ROUTED', 'assignAll' => false],
        ));
        $this->fail('Expected the stale authoritative source snapshot to fail closed.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('raw_mark');
    }

    expect($raceTriggered)->toBeTrue()
        ->and(Employee::withoutCompanyScope()->whereIn('external_id', ['CLOCK-ROUTED', 'CLOCK-CHANGED'])->exists())->toBeFalse()
        ->and(EmployeeScheduleAssignment::withoutCompanyScope()->count())->toBe(0)
        ->and(AttendanceFactGeneration::withoutCompanyScope()->count())->toBe(0)
        ->and($mark->refresh()->employee_external_id)->toBe('CLOCK-ROUTED')
        ->and($mark->pay_period_id)->toBe($period->id)
        ->and($mark->event_at->toDateTimeString())->toBe($originalEventAt)
        ->and($mark->employee_id)->toBeNull()
        ->and($mark->status)->toBe('unknown_employee')
        ->and($mark->metadata)->toBeNull();
})->with(['external_code', 'pay_period', 'event_time']);

test('enforces employee and mark permissions inside the revision module', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create();
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-DENIED',
        'event_at' => $period->start_date->addDay()->setTime(8, 0),
        'status' => 'unknown_employee',
    ]);

    expect(fn () => app(AuditedRawMarkRevision::class)->createEmployee(unknownEmployeeCommand(
        $mark, $profile, $actor, ['paymentCode' => 'PAY-DENIED', 'reason' => 'Intento sin autorización.'],
    )))->toThrow(AuthorizationException::class);

    expect(Employee::withoutCompanyScope()->where('external_id', 'CLOCK-DENIED')->exists())->toBeFalse()
        ->and($mark->refresh()->employee_id)->toBeNull();
});

test('fails closed when the period or source mark became immutable or stale', function (string $state) {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'status' => $state === 'locked_period' ? 'processed' : 'draft',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-STALE-'.$state,
        'event_at' => $period->start_date->addDay()->setTime(8, 0),
        'status' => $state === 'stale_mark' ? 'corrected' : 'unknown_employee',
    ]);

    expect(fn () => app(AuditedRawMarkRevision::class)->createEmployee(unknownEmployeeCommand(
        $mark, $profile, $actor, ['paymentCode' => 'PAY-'.$state, 'reason' => 'Revisión de estado autoritativa.'],
    )))->toThrow(ValidationException::class);

    expect(Employee::withoutCompanyScope()->where('external_id', 'CLOCK-STALE-'.$state)->exists())->toBeFalse()
        ->and($mark->refresh()->employee_id)->toBeNull();
})->with(['locked_period', 'stale_mark']);

test('rejects a schedule profile from another company before creating the employee', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $foreignProfile = WorkScheduleProfile::factory()->forCompany($otherCompany)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-FOREIGN',
        'event_at' => $period->start_date->addDay()->setTime(8, 0),
        'status' => 'unknown_employee',
    ]);

    expect(fn () => app(AuditedRawMarkRevision::class)->createEmployee(unknownEmployeeCommand(
        $mark, $foreignProfile, $actor, ['paymentCode' => 'PAY-FOREIGN', 'reason' => 'Intento con jornada de otra empresa.'],
    )))->toThrow(ValidationException::class);

    expect(Employee::withoutCompanyScope()->where('external_id', 'CLOCK-FOREIGN')->exists())->toBeFalse()
        ->and($mark->refresh()->employee_id)->toBeNull();
});

test('rejects a duplicate employee identity without changing the source mark', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    Employee::factory()->forCompany($company)->create(['external_id' => 'CLOCK-DUPLICATE']);
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-DUPLICATE',
        'event_at' => $period->start_date->addDay()->setTime(8, 0),
        'status' => 'unknown_employee',
    ]);

    expect(fn () => app(AuditedRawMarkRevision::class)->createEmployee(unknownEmployeeCommand(
        $mark, $profile, $actor, ['paymentCode' => 'PAY-DUPLICATE', 'reason' => 'Intento con identidad duplicada.'],
    )))->toThrow(ValidationException::class);

    expect(Employee::withoutCompanyScope()->where('external_id', 'CLOCK-DUPLICATE')->count())->toBe(1)
        ->and($mark->refresh()->employee_id)->toBeNull()
        ->and($mark->metadata)->toBeNull();
});
