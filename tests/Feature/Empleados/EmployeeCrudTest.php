<?php

use App\Livewire\Empleados\Create;
use App\Livewire\Empleados\Edit;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
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
        ->call('save')
        ->assertHasNoErrors();

    $employee = Employee::query()
        ->where('external_id', '8888')
        ->where('company_id', $companyB->id)
        ->first();

    expect($employee)->not->toBeNull();
    expect($employee->first_name)->toBe('Ana');
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

    $this->actingAs($admin);

    Livewire::test(Edit::class, ['employee' => $employee])
        ->set('dni', '2222222222222')
        ->set('expected_salary', '15000')
        ->set('job_title', 'Supervisor')
        ->call('save')
        ->assertHasNoErrors();

    $employee->refresh();

    expect($employee->revisions()->count())->toBe(3);
    expect($employee->revisions()->where('field', 'dni')->where('new_value', '2222222222222')->exists())->toBeTrue();
    expect($employee->revisions()->where('field', 'expected_salary')->where('new_value', '15000.00')->exists())->toBeTrue();
    expect($employee->revisions()->where('field', 'job_title')->where('new_value', 'Supervisor')->exists())->toBeTrue();
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
