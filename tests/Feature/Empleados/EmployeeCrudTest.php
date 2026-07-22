<?php

use App\Livewire\Empleados\Create;
use App\Livewire\Empleados\Edit;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('company admin can list only own company employees', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    Employee::factory()->count(2)->forCompany($companyA)->create();
    Employee::factory()->count(3)->forCompany($companyB)->create();

    $own = $companyA->employees()->first();
    $other = $companyB->employees()->first();

    $this->actingAs($admin);
    $response = $this->get('/empleados');

    $response->assertOk();
    $response->assertSee($own->full_name);
    $response->assertDontSee($other->full_name);
});

test('company admin cannot edit employee of other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $otherEmployee = Employee::factory()->forCompany($companyB)->create();

    $this->actingAs($admin);
    $response = $this->get('/empleados/'.$otherEmployee->id.'/editar');

    $this->assertTrue(in_array($response->getStatusCode(), [403, 404], true));
});

test('super admin can see employees of active company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('super_admin');

    Employee::factory()->count(2)->forCompany($companyA)->create();
    Employee::factory()->count(3)->forCompany($companyB)->create();

    $own = $companyA->employees()->first();
    $other = $companyB->employees()->first();

    $this->actingAs($admin);
    session(['active_company_id' => $companyA->id]);
    app(CurrentCompany::class)->set($companyA);

    $response = $this->get('/empleados');

    $response->assertOk();
    $response->assertSee($own->full_name);
    $response->assertDontSee($other->full_name);
});

test('create employee requires permission', function () {
    $user = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);
    $response = $this->get('/empleados/crear');

    $response->assertStatus(403);
});

test('company admin create employee forces own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);

    Livewire::test(Create::class)
        ->set('external_id', '9999')
        ->set('first_name', 'Juan')
        ->set('last_name', 'Pérez')
        ->set('dni', '1234567890123')
        ->set('company_id', $companyB->id)
        ->call('save')
        ->assertHasErrors('company_id');
});

test('super admin can switch company and create employee', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($companyB)->create();

    $admin = User::factory()->create([
        'company_id' => null,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    session(['active_company_id' => $companyB->id]);
    app(CurrentCompany::class)->set($companyB);

    Livewire::test(Create::class)
        ->set('external_id', '8888')
        ->set('first_name', 'Ana')
        ->set('last_name', 'López')
        ->set('dni', '1234567890123')
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_reason', 'Asignación inicial')
        ->call('save')
        ->assertHasNoErrors();

    $employee = Employee::query()
        ->where('external_id', '8888')
        ->where('company_id', $companyB->id)
        ->first();

    expect($employee)->not->toBeNull();
    expect($employee->first_name)->toBe('Ana');
});

test('company admin creates an employee with an effective schedule assignment', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create([
        'name' => 'Jornada diurna',
    ]);
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);

    Livewire::test(Create::class)
        ->set('external_id', '7001')
        ->set('first_name', 'María')
        ->set('last_name', 'Ramos')
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_effective_from', '2026-07-01')
        ->set('schedule_reason', 'Asignación al ingresar')
        ->call('save')
        ->assertHasNoErrors();

    $employee = Employee::query()->where('external_id', '7001')->firstOrFail();
    $assignment = $employee->scheduleAssignments()->firstOrFail();

    expect($assignment->work_schedule_profile_id)->toBe($profile->id)
        ->and($assignment->effective_from->toDateString())->toBe('2026-07-01')
        ->and($assignment->reason)->toBe('Asignación al ingresar')
        ->and($assignment->assigned_by)->toBe($admin->id);
});

test('employee forms expose only active schedule profiles from the selected company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    WorkScheduleProfile::factory()->forCompany($company)->create(['name' => 'Jornada permitida']);
    WorkScheduleProfile::factory()->forCompany($company)->create([
        'name' => 'Jornada anterior',
        'is_active' => false,
    ]);
    $foreignProfile = WorkScheduleProfile::factory()->forCompany($otherCompany)->create([
        'name' => 'Jornada ajena',
    ]);
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);

    Livewire::test(Create::class)
        ->assertSee('Jornada permitida')
        ->assertDontSee('Jornada anterior')
        ->assertDontSee('Jornada ajena')
        ->set('external_id', '7002')
        ->set('first_name', 'José')
        ->set('last_name', 'Paz')
        ->set('schedule_profile_id', $foreignProfile->id)
        ->set('schedule_reason', 'Asignación inválida')
        ->call('save')
        ->assertHasErrors('schedule_profile_id');
});

test('duplicate external id within same company is rejected', function () {
    $company = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    Employee::factory()->forCompany($company)->create(['external_id' => '1111']);

    $this->actingAs($admin);

    Livewire::test(Create::class)
        ->set('external_id', '1111')
        ->set('first_name', 'Pedro')
        ->set('last_name', 'Gómez')
        ->set('dni', '1234567890123')
        ->call('save')
        ->assertHasErrors('external_id');
});

test('duplicate external id in other company is allowed', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($companyA)->create();

    $admin = User::factory()->create([
        'company_id' => $companyA->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    Employee::factory()->forCompany($companyB)->create(['external_id' => '2222']);

    $this->actingAs($admin);

    Livewire::test(Create::class)
        ->set('external_id', '2222')
        ->set('first_name', 'Luis')
        ->set('last_name', 'Martínez')
        ->set('dni', '1234567890123')
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_reason', 'Asignación inicial')
        ->call('save')
        ->assertHasNoErrors();

    expect(Employee::query()
        ->where('external_id', '2222')
        ->where('company_id', $companyA->id)
        ->exists())->toBeTrue();
});

test('update employee audits sensitive fields', function () {
    $company = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $employee = Employee::factory()->forCompany($company)->create([
        'dni' => '1111111111111',
        'expected_salary' => 10000,
        'job_title' => 'Operador',
    ]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['employee' => $employee])
        ->set('dni', '2222222222222')
        ->set('expected_salary', '15000')
        ->set('job_title', 'Supervisor')
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_reason', 'Asignación inicial')
        ->call('save')
        ->assertHasNoErrors();

    $employee->refresh();

    expect($employee->revisions()->count())->toBe(3);
    expect($employee->revisions()->where('field', 'dni')->where('new_value', '2222222222222')->exists())->toBeTrue();
    expect($employee->revisions()->where('field', 'expected_salary')->where('new_value', '15000.00')->exists())->toBeTrue();
    expect($employee->revisions()->where('field', 'job_title')->where('new_value', 'Supervisor')->exists())->toBeTrue();
});

test('an employee cannot be transferred directly to another company', function () {
    $companyA = Company::factory()->create(['name' => 'Empresa histórica']);
    $companyB = Company::factory()->create(['name' => 'Empresa destino']);
    $profile = WorkScheduleProfile::factory()->forCompany($companyA)->create();
    $superAdmin = User::factory()->create(['company_id' => null]);
    $superAdmin->assignRole('super_admin');
    $employee = Employee::factory()->forCompany($companyA)->create();

    app(EmployeeScheduleAssigner::class)->assign(
        $employee,
        $profile,
        '2026-07-01',
        'Jornada inicial',
        $superAdmin,
    );

    $this->actingAs($superAdmin);

    Livewire::test(Edit::class, ['employee' => $employee])
        ->assertSee('Empresa histórica')
        ->assertDontSee('Empresa destino')
        ->set('company_id', $companyB->id)
        ->call('save')
        ->assertHasErrors('company_id');

    expect($employee->fresh()->company_id)->toBe($companyA->id)
        ->and(fn () => $employee->fresh()->update(['company_id' => $companyB->id]))
        ->toThrow(LogicException::class, 'Employee company is immutable after creation.');

    expect($employee->fresh()->company_id)->toBe($companyA->id);
});

test('company admin assigns a new employee schedule from an effective date', function () {
    $company = Company::factory()->create();
    $dayProfile = WorkScheduleProfile::factory()->forCompany($company)->create(['name' => 'Diurna']);
    $nightProfile = WorkScheduleProfile::factory()->forCompany($company)->create(['name' => 'Nocturna']);
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create();
    $initial = app(EmployeeScheduleAssigner::class)->assign(
        $employee,
        $dayProfile,
        '2026-07-01',
        'Turno inicial',
        $admin,
    );

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['employee' => $employee])
        ->set('schedule_profile_id', $nightProfile->id)
        ->set('schedule_effective_from', '2026-07-20')
        ->set('schedule_reason', 'Rotación nocturna')
        ->call('save')
        ->assertHasNoErrors();

    $latest = $employee->scheduleAssignments()->latest('effective_from')->firstOrFail();

    expect($initial->fresh()->effective_to?->toDateString())->toBe('2026-07-19')
        ->and($latest->work_schedule_profile_id)->toBe($nightProfile->id)
        ->and($latest->effective_from->toDateString())->toBe('2026-07-20')
        ->and($latest->reason)->toBe('Rotación nocturna');
});

test('deactivate employee requires permission', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();

    $user = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);
    $response = $this->post('/empleados/'.$employee->id.'/desactivar');

    $response->assertStatus(403);
});

test('soft delete employee preserves revisions', function () {
    $company = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $employee = Employee::factory()->forCompany($company)->create([
        'dni' => '1111111111111',
    ]);

    $employee->update(['dni' => '2222222222222']);

    $this->actingAs($admin);

    $response = $this->delete('/empleados/'.$employee->id);
    $response->assertRedirect('/empleados');

    $employee->refresh();

    expect($employee->trashed())->toBeTrue();
    expect($employee->revisions()->count())->toBe(1);
});
