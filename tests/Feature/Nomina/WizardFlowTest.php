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
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function setUpCompanyAndPayPeriod(string $payPeriodStatus = 'validating'): array
{
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-11',
        'status' => $payPeriodStatus,
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    return [$company, $payPeriod, $file, $admin];
}

function assignWizardSchedule(Company $company, Employee $employee): void
{
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();

    foreach (Company::defaultWorkSchedules() as $day => $definition) {
        WorkSchedule::factory()->forProfile($profile)->create([
            'day_of_week' => $day,
            ...$definition,
        ]);
    }

    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2020-01-01', 'Jornada inicial');
}

test('saveDraft sets pay period status to validating', function () {
    [$company, $payPeriod, $file, $admin] = setUpCompanyAndPayPeriod('uploaded');

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($payPeriod->fresh()->status)->toBe('validating');
});

test('continueToReady sets status to ready when all marks are clean', function () {
    [$company, $payPeriod, $file, $admin] = setUpCompanyAndPayPeriod('validating');
    $employee = Employee::factory()->forCompany($company)->create();
    assignWizardSchedule($company, $employee);

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => $employee->external_id,
        'employee_id' => $employee->id,
        'event_at' => Carbon::parse('2026-01-05 06:00:00'),
        'status' => 'valid',
    ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => $employee->external_id,
        'employee_id' => $employee->id,
        'event_at' => Carbon::parse('2026-01-05 14:00:00'),
        'status' => 'valid',
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('continueToReady')
        ->assertHasNoErrors();

    expect($payPeriod->fresh()->status)->toBe('ready');
});

test('continueToReady with pending marks opens confirmation modal and does not advance status', function () {
    [$company, $payPeriod, $file, $admin] = setUpCompanyAndPayPeriod('validating');

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => 'unknown-123',
        'employee_id' => null,
        'event_at' => Carbon::parse('2026-01-05 06:00:00'),
        'status' => 'unknown_employee',
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('continueToReady')
        ->assertHasNoErrors();

    $component
        ->assertSet('showReadyConfirm', true)
        ->assertSet('readyMessage', 'Aún existen marcas pendientes, desconocidas, fuera de período o duplicadas. ¿Desea continuar de todas formas?');

    expect($payPeriod->fresh()->status)->toBe('validating');
});

test('an unreviewed overtime candidate cannot be bypassed when advancing to ready', function () {
    [$company, $payPeriod, $file, $admin] = setUpCompanyAndPayPeriod('validating');
    $employee = Employee::factory()->forCompany($company)->create();
    assignWizardSchedule($company, $employee);

    foreach (['2026-01-05 06:00:00', '2026-01-05 14:30:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)
            ->forEmployee($employee)->create(['event_at' => $eventAt, 'status' => 'valid']);
    }

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('continueToReady')
        ->assertSet('showReadyConfirm', false)
        ->assertCount('readinessBlockers', 1)
        ->assertSee('Revisión obligatoria pendiente')
        ->set('showReadyConfirm', true)
        ->call('confirmContinueToReady')
        ->assertCount('readinessBlockers', 1);

    expect($payPeriod->fresh()->status)->toBe('validating');
});

test('confirmContinueToReady advances pay period to ready after user acceptance', function () {
    [$company, $payPeriod, $file, $admin] = setUpCompanyAndPayPeriod('validating');

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => 'unknown-999',
        'employee_id' => null,
        'event_at' => Carbon::parse('2026-01-06 06:00:00'),
        'status' => 'unknown_employee',
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('showReadyConfirm', true)
        ->set('readyMessage', 'confirmation')
        ->call('confirmContinueToReady')
        ->assertHasNoErrors();

    expect($payPeriod->fresh()->status)->toBe('ready');
});

test('saveDraft and continueToReady are no-ops when pay period is processed or locked', function (string $blockedStatus) {
    [$company, $payPeriod, $file, $admin] = setUpCompanyAndPayPeriod($blockedStatus);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($payPeriod->fresh()->status)->toBe($blockedStatus);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('continueToReady')
        ->assertHasNoErrors();

    expect($payPeriod->fresh()->status)->toBe($blockedStatus);
})->with([
    'processed' => ['processed'],
    'approved' => ['approved'],
    'exported' => ['exported'],
    'cancelled' => ['cancelled'],
]);

test('markCorrected changes raw mark status to corrected and records audit entry', function () {
    [$company, $payPeriod, $file, $admin] = setUpCompanyAndPayPeriod('validating');
    $employee = Employee::factory()->forCompany($company)->create();

    $rawMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => $employee->external_id,
        'employee_id' => $employee->id,
        'event_at' => Carbon::parse('2026-01-05 06:00:00'),
        'status' => 'valid',
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('markCorrected', $rawMark->id)
        ->assertHasNoErrors();

    $rawMark->refresh();

    expect($rawMark->status)->toBe('corrected')
        ->and($rawMark->metadata['revisions'])->not->toBeEmpty();

    $revisions = $rawMark->metadata['revisions'] ?? [];
    $lastAudit = end($revisions);

    expect($lastAudit['action'])->toBe('mark_corrected')
        ->and($lastAudit['user_id'])->toBe($admin->id)
        ->and($lastAudit['previous_status'])->toBe('valid');
});

test('deleteRawMark sets raw mark status to deleted and records audit entry', function () {
    [$company, $payPeriod, $file, $admin] = setUpCompanyAndPayPeriod('validating');
    $employee = Employee::factory()->forCompany($company)->create();

    $rawMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => $employee->external_id,
        'employee_id' => $employee->id,
        'event_at' => Carbon::parse('2026-01-05 06:00:00'),
        'status' => 'valid',
    ]);

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->call('openDeleteRawMark', $rawMark->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deleteRawMarkId', $rawMark->id)
        ->call('deleteRawMark')
        ->assertHasNoErrors();

    $rawMark->refresh();

    expect($rawMark->status)->toBe('deleted')
        ->and($rawMark->metadata['revisions'])->not->toBeEmpty();

    $revisions = $rawMark->metadata['revisions'] ?? [];
    $lastAudit = end($revisions);

    expect($lastAudit['action'])->toBe('delete')
        ->and($lastAudit['user_id'])->toBe($admin->id)
        ->and($lastAudit['previous_status'])->toBe('valid');
});
