<?php

use App\Livewire\Auditoria\Index;
use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRevision;
use App\Models\EmployeeScheduleAssignment;
use App\Models\JustifiedAbsence;
use App\Models\LoginAttempt;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('guest is redirected from auditoria', function () {
    $this->get('/auditoria')->assertRedirect('/login');
});

test('user without audit.view permission cannot access auditoria', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->actingAs($user)
        ->get('/auditoria')
        ->assertStatus(403);
});

test('super admin sees all audit entries across companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->create(['company_id' => $companyA->id]);
    $adminB = User::factory()->create(['company_id' => $companyB->id]);

    LoginAttempt::factory()->create(['company_id' => $companyA->id, 'email' => $adminA->email, 'success' => true]);
    LoginAttempt::factory()->create(['company_id' => $companyB->id, 'email' => $adminB->email, 'success' => true]);

    $employeeA = Employee::factory()->forCompany($companyA)->create();
    EmployeeRevision::factory()->create(['employee_id' => $employeeA->id, 'user_id' => $adminA->id]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    Livewire::actingAs($super)
        ->test(Index::class)
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 3;
        });
});

test('company admin sees only own company audit entries', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->create(['company_id' => $companyA->id]);
    $adminB = User::factory()->create(['company_id' => $companyB->id]);

    LoginAttempt::factory()->create(['company_id' => $companyA->id, 'email' => $adminA->email, 'success' => true]);
    LoginAttempt::factory()->create(['company_id' => $companyB->id, 'email' => $adminB->email, 'success' => true]);

    $adminA->assignRole('company_admin');

    Livewire::actingAs($adminA)
        ->test(Index::class)
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('company admin without a company sees no tenant audit entries', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    LoginAttempt::factory()->create(['company_id' => $companyA->id]);
    LoginAttempt::factory()->create(['company_id' => $companyB->id]);

    $admin = User::factory()->create(['company_id' => null]);
    $admin->assignRole('company_admin');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 0);
});

test('super admin audit feed uses the global company context', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $adminA = User::factory()->create(['company_id' => $companyA->id]);
    $adminB = User::factory()->create(['company_id' => $companyB->id]);

    LoginAttempt::factory()->create(['company_id' => $companyA->id, 'email' => $adminA->email, 'success' => true]);
    LoginAttempt::factory()->create(['company_id' => $companyB->id, 'email' => $adminB->email, 'success' => true]);

    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $csrfToken = 'audit-company-test-token';

    $this->withSession(['_token' => $csrfToken])
        ->actingAs($super)
        ->post(route('current-company.update'), [
            '_token' => $csrfToken,
            'company' => $companyA->slug,
        ])
        ->assertRedirect(route('dashboard'));

    $this->get(route('auditoria.index'))
        ->assertOk()
        ->assertSee($adminA->email)
        ->assertDontSee($adminB->email);
});

test('type filter works', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    LoginAttempt::factory()->create(['company_id' => $company->id, 'email' => $admin->email, 'success' => true]);

    $employee = Employee::factory()->forCompany($company)->create();
    EmployeeRevision::factory()->create(['employee_id' => $employee->id, 'user_id' => $admin->id]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('type', 'login_attempt')
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('date filter works', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    LoginAttempt::factory()->create([
        'company_id' => $company->id,
        'email' => $admin->email,
        'success' => true,
        'created_at' => now()->subDays(2),
    ]);
    LoginAttempt::factory()->create([
        'company_id' => $company->id,
        'email' => $admin->email,
        'success' => true,
        'created_at' => now()->subDays(30),
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('from', now()->subDays(10)->format('Y-m-d'))
        ->set('to', now()->format('Y-m-d'))
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('user filter works', function () {
    $company = Company::factory()->create();
    $adminA = User::factory()->create(['company_id' => $company->id, 'email' => 'admin_a@example.com']);
    $adminB = User::factory()->create(['company_id' => $company->id, 'email' => 'other@example.com']);
    $adminA->assignRole('company_admin');

    LoginAttempt::factory()->create(['company_id' => $company->id, 'email' => $adminA->email, 'success' => true]);
    LoginAttempt::factory()->create(['company_id' => $company->id, 'email' => $adminB->email, 'success' => true]);

    Livewire::actingAs($adminA)
        ->test(Index::class)
        ->set('user', 'admin_a')
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('pagination returns 25 items per page', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    LoginAttempt::factory()->count(30)->create([
        'company_id' => $company->id,
        'email' => $admin->email,
        'success' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertViewHas('entries', function ($entries) {
            return $entries->count() === 25 && $entries->total() === 30;
        });
});

test('raw mark revisions appear in audit feed', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    $rawMark = RawMark::factory()->forCompany($company)->create([
        'metadata' => [
            'revisions' => [
                [
                    'action' => 'edit_event_at',
                    'user_id' => $admin->id,
                    'reason' => 'El reloj registró una hora incorrecta',
                    'old_event_at' => '2026-01-05 06:00:00',
                    'new_event_at' => '2026-01-05 06:15:00',
                    'at' => now()->subMinute()->toDateTimeString(),
                ],
                [
                    'action' => 'mark_corrected',
                    'user_id' => $admin->id,
                    'reason' => 'Validación contra reporte del supervisor',
                    'previous_status' => 'unknown_employee',
                    'new_status' => 'corrected',
                    'at' => now()->subSeconds(30)->toDateTimeString(),
                ],
                [
                    'action' => 'delete',
                    'user_id' => $admin->id,
                    'reason' => 'La marca no corresponde a un hecho real',
                    'previous_status' => 'valid',
                    'new_status' => 'deleted',
                    'at' => now()->toDateTimeString(),
                ],
            ],
        ],
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('type', 'mark_revision')
        ->assertSee('2026-01-05 06:00:00 a 2026-01-05 06:15:00')
        ->assertSee('El reloj registró una hora incorrecta')
        ->assertSee('unknown_employee a corrected')
        ->assertSee('Validación contra reporte del supervisor')
        ->assertSee('valid a deleted')
        ->assertSee('La marca no corresponde a un hecho real')
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 3;
        });
});

test('payroll state transitions appear in audit feed', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');

    PayPeriod::factory()->forCompany($company)->create([
        'status' => 'approved',
        'metadata' => [
            'approved_at' => now()->toDateTimeString(),
            'approved_by' => $admin->id,
        ],
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('type', 'payroll_state')
        ->assertViewHas('entries', function ($entries) {
            return $entries->total() === 1;
        });
});

test('attendance assignments decisions and exceptions appear with exact audit context', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create([
        'first_name' => 'María',
        'last_name' => 'Guardia',
    ]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create([
        'name' => 'Guardia nocturna',
        'version' => 2,
    ]);
    $period = PayPeriod::factory()->forCompany($company)->create();
    EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_profile_id' => $profile->id,
        'effective_from' => '2026-07-01',
        'assigned_by' => $admin->id,
        'reason' => 'Cambio de puesto nocturno',
    ]);
    OvertimeDecision::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-07-20',
        'starts_at' => '2026-07-20 14:00:00',
        'ends_at' => '2026-07-20 14:30:00',
        'minutes' => 30,
        'decision' => OvertimeDecision::APPROVED,
        'reason' => 'Cobertura autorizada',
        'decided_by' => $admin->id,
    ]);
    AttendanceException::factory()->create([
        'company_id' => $company->id,
        'pay_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-07-21',
        'starts_at' => '2026-07-21 06:00:00',
        'ends_at' => '2026-07-21 06:15:00',
        'minutes' => 15,
        'decision' => AttendanceException::GRANTED,
        'reason' => 'Demora justificada',
        'decided_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)->test(Index::class)
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 3)
        ->assertSee('Autorizaciones de horas extra')
        ->assertSee('Autorización de hora extra')
        ->assertSee('Guardia nocturna v2')
        ->assertSee('Cambio de puesto nocturno')
        ->assertSee('30 min')
        ->assertSee('Cobertura autorizada')
        ->assertSee('15 min')
        ->assertSee('Demora justificada');
});

test('manual marks and payroll reopenings explain the factual audit event', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create();
    $period = PayPeriod::factory()->forCompany($company)->create([
        'metadata' => [
            'reopenings' => [[
                'from_status' => 'processed',
                'to_status' => 'validating',
                'reason' => 'Corregir una salida omitida',
                'user_id' => $admin->id,
                'invalidated_results' => 4,
                'at' => now()->toDateTimeString(),
            ]],
        ],
    ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create([
        'uploaded_file_id' => null,
        'raw_line' => null,
        'row_number' => null,
        'source' => RawMark::SOURCE_MANUAL,
        'event_at' => '2026-07-20 14:00:00',
        'metadata' => ['revisions' => [[
            'action' => 'manual_create',
            'user_id' => $admin->id,
            'work_date' => '2026-07-20',
            'event_at' => '2026-07-20 14:00:00',
            'reason' => 'El reloj omitió la salida',
            'at' => now()->toDateTimeString(),
        ]]],
    ]);

    Livewire::actingAs($admin)->test(Index::class)
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 2)
        ->assertSee('Marca manual')
        ->assertSee('El reloj omitió la salida')
        ->assertSee('reabierto')
        ->assertSee('Corregir una salida omitida')
        ->assertSee('4 resultados invalidados');
});

test('full day absence revisions expose exact schedule snapshots and remain tenant isolated', function () {
    $company = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create([
        'first_name' => 'María',
        'last_name' => 'Guardia',
    ]);
    $period = PayPeriod::factory()->forCompany($company)->create();
    $firstSnapshot = [
        'reason' => 'permission',
        'notes' => 'Cobertura diurna autorizada',
        'justified_by' => $admin->id,
        'schedule_fingerprint' => 'day-fingerprint',
        'scheduled_start' => '2026-07-20T06:00:00-04:00',
        'scheduled_end' => '2026-07-20T14:00:00-04:00',
        'scheduled_minutes' => 480,
        'rate_minutes' => ['ordinary' => 480, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
    ];
    $secondSnapshot = [
        'reason' => 'other',
        'notes' => 'Reautorizada para guardia nocturna',
        'justified_by' => $admin->id,
        'schedule_fingerprint' => 'night-fingerprint',
        'scheduled_start' => '2026-07-20T18:00:00-04:00',
        'scheduled_end' => '2026-07-21T06:00:00-04:00',
        'scheduled_minutes' => 720,
        'rate_minutes' => ['ordinary' => 0, 'extra25' => 0, 'extra50' => 360, 'extra75' => 360, 'extra100' => 0],
    ];

    JustifiedAbsence::factory()->forCompany($company)->forPayPeriod($period)->forEmployee($employee)->create([
        'date' => '2026-07-20',
        'reason' => 'other',
        'notes' => $secondSnapshot['notes'],
        'justified_by' => $admin->id,
        'schedule_fingerprint' => $secondSnapshot['schedule_fingerprint'],
        'scheduled_start' => $secondSnapshot['scheduled_start'],
        'scheduled_end' => $secondSnapshot['scheduled_end'],
        'scheduled_minutes' => $secondSnapshot['scheduled_minutes'],
        'rate_minutes' => $secondSnapshot['rate_minutes'],
        'metadata' => ['revisions' => [
            [
                'action' => 'justify_full_day_absence',
                'user_id' => $admin->id,
                'old_values' => null,
                'new_values' => $firstSnapshot,
                'at' => now()->subMinute()->toIso8601String(),
            ],
            [
                'action' => 'justify_full_day_absence',
                'user_id' => $admin->id,
                'old_values' => $firstSnapshot,
                'new_values' => $secondSnapshot,
                'at' => now()->toIso8601String(),
            ],
        ]],
    ]);

    $foreignEmployee = Employee::factory()->forCompany($foreignCompany)->create();
    $foreignPeriod = PayPeriod::factory()->forCompany($foreignCompany)->create();
    JustifiedAbsence::factory()->forCompany($foreignCompany)->forPayPeriod($foreignPeriod)->forEmployee($foreignEmployee)->create([
        'metadata' => ['revisions' => [[
            'action' => 'justify_full_day_absence',
            'user_id' => null,
            'old_values' => null,
            'new_values' => $firstSnapshot,
            'at' => now()->toIso8601String(),
        ]]],
    ]);

    Livewire::actingAs($admin)->test(Index::class)
        ->set('type', 'full_day_absence')
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 2)
        ->assertSee('Justificaciones de jornada completa')
        ->assertSee('Justificación de jornada completa')
        ->assertSee('María Guardia')
        ->assertSee('sin justificación previa')
        ->assertSee('06:00')
        ->assertSee('14:00')
        ->assertSee('480 min')
        ->assertSee('18:00')
        ->assertSee('720 min')
        ->assertSee('50%: 360 min')
        ->assertSee('75%: 360 min')
        ->assertSee('day-fingerprint')
        ->assertSee('night-fingerprint')
        ->assertSee('Reautorizada para guardia nocturna');
});

test('new attendance audit types remain isolated by company', function (string $type, string $model) {
    $company = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    foreach ([$company, $foreignCompany] as $eventCompany) {
        $employee = Employee::factory()->forCompany($eventCompany)->create();
        $attributes = ['company_id' => $eventCompany->id, 'employee_id' => $employee->id];

        if ($model === EmployeeScheduleAssignment::class) {
            $attributes['work_schedule_profile_id'] = WorkScheduleProfile::factory()->forCompany($eventCompany)->create()->id;
        } else {
            $attributes['pay_period_id'] = PayPeriod::factory()->forCompany($eventCompany)->create()->id;
        }

        $model::factory()->create($attributes);
    }

    Livewire::actingAs($admin)->test(Index::class)
        ->set('type', $type)
        ->assertViewHas('entries', fn ($entries) => $entries->total() === 1);
})->with([
    ['schedule_assignment', EmployeeScheduleAssignment::class],
    ['overtime_decision', OvertimeDecision::class],
    ['attendance_exception', AttendanceException::class],
]);
