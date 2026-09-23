<?php

use App\Livewire\Empleados\Create;
use App\Livewire\Empleados\Edit;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePositionAssignment;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Employees\EmployeePositionAssigner;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
})->afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the migration backfills existing position history with trimmed titles and skips blank titles', function () {
    $company = Company::factory()->create();
    $hired = Employee::factory()->forCompany($company)->create([
        'job_title' => 'Operador',
        'hired_at' => '2020-03-04',
        'created_at' => '2021-04-05 12:00:00',
    ]);
    $created = Employee::factory()->forCompany($company)->create([
        'job_title' => 'Analista',
        'hired_at' => null,
        'created_at' => '2022-06-07 12:00:00',
    ]);
    $epoch = Employee::factory()->forCompany($company)->create([
        'job_title' => '  Supervisor  ',
        'hired_at' => null,
    ]);
    $blank = Employee::factory()->forCompany($company)->create([
        'job_title' => " \t ",
    ]);
    DB::table('employees')->where('id', $epoch->id)->update(['created_at' => null]);

    $migration = require database_path('migrations/2026_09_23_000001_create_employee_position_assignments_table.php');
    $migration->down();
    $migration->up();

    $assignments = DB::table('employee_position_assignments')->get()->keyBy('employee_id');

    expect($assignments[$hired->id]->effective_from)->toBe('2020-03-04')
        ->and($assignments[$created->id]->effective_from)->toBe('2022-06-07')
        ->and($assignments[$epoch->id]->effective_from)->toBe('1970-01-01')
        ->and($assignments[$epoch->id]->title)->toBe('Supervisor')
        ->and($assignments->has($blank->id))->toBeFalse()
        ->and($assignments[$hired->id]->reason)->toBe('Asignación inicial migrada');
});

test('creating an employee with a job title creates the initial position assignment', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $this->actingAs($admin);

    Livewire::test(Create::class)
        ->set('external_id', 'POSITION-001')
        ->set('first_name', 'Ana')
        ->set('last_name', 'Pérez')
        ->set('job_title', 'Analista')
        ->set('hired_at', '2026-06-10')
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_effective_from', '2026-06-10')
        ->set('schedule_reason', 'Ingreso')
        ->call('save')
        ->assertHasNoErrors();

    $employee = Employee::query()->where('external_id', 'POSITION-001')->firstOrFail();
    $assignment = $employee->positionAssignments()->sole();

    expect($assignment->title)->toBe('Analista')
        ->and($assignment->effective_from->toDateString())->toBe('2026-06-10')
        ->and($assignment->effective_to)->toBeNull()
        ->and($assignment->assigned_by)->toBe($admin->id)
        ->and($assignment->reason)->toBe('Ingreso');
});

test('creating an employee normalizes the position title and ignores a blank title', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Create::class)
        ->set('external_id', 'POSITION-TRIMMED')
        ->set('first_name', 'Ana')
        ->set('last_name', 'Pérez')
        ->set('job_title', '  Analista  ')
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_reason', 'Ingreso')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(Create::class)
        ->set('external_id', 'POSITION-BLANK')
        ->set('first_name', 'Luis')
        ->set('last_name', 'Paz')
        ->set('job_title', " \t ")
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_reason', 'Ingreso')
        ->call('save')
        ->assertHasNoErrors();

    $trimmed = Employee::query()->where('external_id', 'POSITION-TRIMMED')->sole();
    $blank = Employee::query()->where('external_id', 'POSITION-BLANK')->sole();

    expect($trimmed->job_title)->toBe('Analista')
        ->and($trimmed->positionAssignments()->sole()->title)->toBe('Analista')
        ->and($blank->job_title)->toBeNull()
        ->and($blank->positionAssignments()->count())->toBe(0);
});

test('creating an employee with a future position keeps the current title empty', function () {
    /** @var TestCase $this */
    CarbonImmutable::setTestNow('2026-07-01 10:00:00');
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Create::class)
        ->set('external_id', 'POSITION-FUTURE')
        ->set('first_name', 'Ana')
        ->set('last_name', 'Pérez')
        ->set('job_title', 'Supervisor')
        ->set('hired_at', '2026-07-15')
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_effective_from', '2026-07-15')
        ->set('schedule_reason', 'Ingreso futuro')
        ->call('save')
        ->assertHasNoErrors();

    $employee = Employee::query()->where('external_id', 'POSITION-FUTURE')->sole();

    expect($employee->job_title)->toBeNull()
        ->and($employee->positionAssignments()->sole()->title)->toBe('Supervisor');
});

test('editing a job title creates history and closes the previous position', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create(['job_title' => 'Analista']);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-01-01', 'Jornada inicial', $admin);
    $first = app(EmployeePositionAssigner::class)->assign($employee, 'Analista', '2026-01-01', 'Asignación inicial', $admin);

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['employee' => $employee])
        ->set('job_title', 'Supervisor')
        ->set('position_effective_from', '2026-07-15')
        ->set('position_reason', 'Promoción')
        ->call('save')
        ->assertHasNoErrors();

    $latest = $employee->positionAssignments()->latest('effective_from')->firstOrFail();

    expect($employee->fresh()->job_title)->toBe('Supervisor')
        ->and($first->fresh()->effective_to?->toDateString())->toBe('2026-07-14')
        ->and($latest->title)->toBe('Supervisor')
        ->and($latest->effective_from->toDateString())->toBe('2026-07-15')
        ->and($latest->reason)->toBe('Promoción');
});

test('a future position assignment does not replace the current title early', function () {
    /** @var TestCase $this */
    CarbonImmutable::setTestNow('2026-07-01 10:00:00');
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create(['job_title' => 'Analista']);
    $assigner = app(EmployeePositionAssigner::class);
    $assigner->assign($employee, 'Analista', '2026-01-01', 'Ingreso', $admin);

    $future = $assigner->assign($employee, 'Supervisor', '2026-07-15', 'Promoción', $admin);

    expect($employee->fresh()->job_title)->toBe('Analista')
        ->and($future->title)->toBe('Supervisor')
        ->and($future->effective_from->toDateString())->toBe('2026-07-15');

    $this->actingAs($admin);
    Livewire::test(Edit::class, ['employee' => $employee->fresh()])
        ->assertSee('Cargo actual')
        ->assertSee('Analista')
        ->assertSee('Programado');
});

test('position edits normalize titles without creating redundant history', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create(['job_title' => 'Analista']);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, now()->toDateString(), 'Jornada inicial', $admin);
    $this->actingAs($admin);

    Livewire::test(Edit::class, ['employee' => $employee])
        ->set('job_title', '  Analista  ')
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->job_title)->toBe('Analista')
        ->and($employee->positionAssignments()->count())->toBe(0);

    Livewire::test(Edit::class, ['employee' => $employee->fresh()])
        ->set('job_title', '  Supervisor  ')
        ->set('position_effective_from', now()->toDateString())
        ->set('position_reason', 'Promoción')
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->job_title)->toBe('Supervisor')
        ->and($employee->positionAssignments()->sole()->title)->toBe('Supervisor');
});

test('editing a blank position to whitespace stores null without history', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create(['job_title' => null]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, now()->toDateString(), 'Jornada inicial', $admin);
    $this->actingAs($admin);

    Livewire::test(Edit::class, ['employee' => $employee])
        ->set('job_title', " \t ")
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->job_title)->toBeNull()
        ->and($employee->positionAssignments()->count())->toBe(0);
});

test('employee and schedule edits roll back when position assignment fails', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create([
        'first_name' => 'Original',
        'job_title' => 'Analista',
    ]);
    $this->actingAs($admin);

    $this->mock(EmployeePositionAssigner::class)
        ->shouldReceive('assign')
        ->once()
        ->andThrow(ValidationException::withMessages(['job_title' => 'Assignment failed.']));

    Livewire::test(Edit::class, ['employee' => $employee])
        ->set('first_name', 'Changed')
        ->set('job_title', 'Supervisor')
        ->set('position_effective_from', now()->toDateString())
        ->set('position_reason', 'Promoción')
        ->set('schedule_profile_id', $profile->id)
        ->set('schedule_effective_from', now()->toDateString())
        ->set('schedule_reason', 'Nueva jornada')
        ->call('save')
        ->assertHasErrors('job_title');

    expect($employee->fresh()->first_name)->toBe('Original')
        ->and($employee->scheduleAssignments()->count())->toBe(0)
        ->and($employee->positionAssignments()->count())->toBe(0);
});

test('duplicate position effective dates are rejected', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create(['job_title' => 'Analista']);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-01-01', 'Jornada inicial', $admin);
    app(EmployeePositionAssigner::class)->assign($employee, 'Analista', '2026-07-15', 'Asignación inicial', $admin);

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['employee' => $employee])
        ->set('job_title', 'Supervisor')
        ->set('position_effective_from', '2026-07-15')
        ->set('position_reason', 'Promoción')
        ->call('save')
        ->assertHasErrors('position_effective_from');

    expect(EmployeePositionAssignment::withoutCompanyScope()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and($employee->fresh()->job_title)->toBe('Analista');
});

test('employee edit displays the current and previous position timeline', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $admin->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create(['job_title' => 'Supervisor']);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-01-01', 'Jornada inicial', $admin);
    $assigner = app(EmployeePositionAssigner::class);
    $assigner->assign($employee, 'Analista', '2026-01-01', 'Ingreso', $admin);
    $assigner->assign($employee, 'Supervisor', '2026-07-15', 'Promoción', $admin);

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['employee' => $employee->fresh()])
        ->assertSee('Cargo actual')
        ->assertSee('Supervisor')
        ->assertSee('Analista')
        ->assertSee('Promoción')
        ->assertSee('Ingreso');
});
