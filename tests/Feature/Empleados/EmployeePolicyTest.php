<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('employee without permission cannot access', function () {
    $company = Company::factory()->create();
    Employee::factory()->count(2)->forCompany($company)->create();

    $user = User::factory()->create([
        'company_id' => $company->id,
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);
    $response = $this->get('/empleados');

    $response->assertStatus(403);
});

test('another company admin cannot destroy our employee', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $admin = User::factory()->create([
        'company_id' => $companyB->id,
        'password' => Hash::make('password'),
    ]);
    $admin->assignRole('company_admin');

    $employee = Employee::factory()->forCompany($companyA)->create();

    $this->actingAs($admin);
    $response = $this->delete('/empleados/'.$employee->id);

    $this->assertTrue(in_array($response->getStatusCode(), [403, 404], true));
});
