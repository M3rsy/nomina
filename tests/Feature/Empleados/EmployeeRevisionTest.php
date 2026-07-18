<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('updating dni creates revision with old and new values', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $user->assignRole('company_admin');
    Auth::setUser($user);

    $employee = Employee::factory()->forCompany(Company::factory()->create())->create([
        'dni' => '1111111111111',
    ]);

    $employee->update(['dni' => '2222222222222']);

    $revision = $employee->revisions()->first();

    expect($employee->revisions()->count())->toBe(1);
    expect($revision->field)->toBe('dni');
    expect($revision->old_value)->toBe('1111111111111');
    expect($revision->new_value)->toBe('2222222222222');
    expect($revision->user_id)->toBe($user->id);
});

test('updating salary creates revision', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $user->assignRole('company_admin');
    Auth::setUser($user);

    $employee = Employee::factory()->forCompany(Company::factory()->create())->create([
        'expected_salary' => 10000,
    ]);

    $employee->update(['expected_salary' => 20000]);

    expect($employee->revisions()->count())->toBe(1);
    expect($employee->revisions()->first()->field)->toBe('expected_salary');
});

test('updating two fields creates two revisions', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $user->assignRole('company_admin');
    Auth::setUser($user);

    $employee = Employee::factory()->forCompany(Company::factory()->create())->create([
        'dni' => '1111111111111',
        'expected_salary' => 10000,
    ]);

    $employee->update([
        'dni' => '2222222222222',
        'expected_salary' => 20000,
    ]);

    expect($employee->revisions()->count())->toBe(2);
    expect($employee->revisions()->pluck('field')->sort()->values()->all())->toBe(['dni', 'expected_salary']);
});

test('non sensitive field change does not create revision', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $user->assignRole('company_admin');
    Auth::setUser($user);

    $employee = Employee::factory()->forCompany(Company::factory()->create())->create([
        'notes' => 'Nota original',
    ]);

    $employee->update(['notes' => 'Nota modificada']);

    expect($employee->revisions()->count())->toBe(0);
});
