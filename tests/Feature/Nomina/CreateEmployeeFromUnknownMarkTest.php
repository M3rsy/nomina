<?php

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Payroll\CreateEmployeeFromUnknownMark;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(fn () => $this->seed(PermissionRoleSeeder::class));

test('creates and schedules an unknown employee while assigning every matching mark', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $marks = RawMark::factory()->count(2)->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create([
        'employee_external_id' => 'CLOCK-77',
        'status' => 'unknown_employee',
    ]);

    $result = app(CreateEmployeeFromUnknownMark::class)->create(
        $marks->first(),
        $profile,
        $actor,
        [
            'first_name' => 'Ana', 'last_name' => 'López', 'dni' => '0801-2000-00001',
            'payment_code' => 'PAY-007', 'job_title' => 'Analista', 'hired_at' => $period->start_date->toDateString(),
        ],
        'Identidad verificada por Recursos Humanos.',
        true,
    );

    expect($result->employee->external_id)->toBe('CLOCK-77')
        ->and($result->assignedMarks)->toBe(2)
        ->and($result->employee->scheduleAssignments()->count())->toBe(1)
        ->and(RawMark::withoutCompanyScope()->whereNull('employee_id')->count())->toBe(0);
});
