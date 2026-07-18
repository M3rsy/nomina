<?php

use App\Models\Company;
use App\Models\Holiday;
use App\Services\CurrentCompany;
use App\Services\PayrollRules;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionRoleSeeder::class);
});

test('holiday is scoped to company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Holiday::factory()->forCompany($companyA)->create([
        'date' => '2026-09-15',
        'name' => 'Día de la independencia',
    ]);

    app(CurrentCompany::class)->set($companyA);

    expect(Holiday::count())->toBe(1)
        ->and(Holiday::first()->company_id)->toBe($companyA->id);
});

test('company b cannot see company a holidays', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Holiday::factory()->forCompany($companyA)->create([
        'date' => '2026-09-15',
        'name' => 'Día de la independencia',
    ]);

    app(CurrentCompany::class)->set($companyB);

    expect(Holiday::count())->toBe(0);
});

test('payroll rules detects active holiday', function () {
    $company = Company::factory()->create();

    Holiday::factory()->forCompany($company)->create([
        'date' => '2026-09-15',
        'name' => 'Día de la independencia',
        'is_active' => true,
    ]);

    $rules = new PayrollRules;

    expect($rules->isHoliday($company, CarbonImmutable::parse('2026-09-15')))->toBeTrue();
});

test('payroll rules ignores inactive holiday', function () {
    $company = Company::factory()->create();

    Holiday::factory()->forCompany($company)->create([
        'date' => '2026-09-15',
        'name' => 'Día de la independencia',
        'is_active' => false,
    ]);

    $rules = new PayrollRules;

    expect($rules->isHoliday($company, CarbonImmutable::parse('2026-09-15')))->toBeFalse();
});
