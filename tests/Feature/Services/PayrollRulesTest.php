<?php

use App\Models\Company;
use App\Models\Holiday;
use App\Services\PayrollRules;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionRoleSeeder::class);
});

test('payroll rules detects a holiday', function () {
    $company = Company::factory()->create();

    Holiday::factory()->forCompany($company)->create([
        'date' => '2026-09-15',
        'name' => 'Día de la independencia',
        'is_active' => true,
    ]);

    $rules = new PayrollRules;

    expect($rules->isHoliday($company, CarbonImmutable::parse('2026-09-15')))->toBeTrue();
});

test('base ordinary hours for monday is eight', function () {
    $company = Company::factory()->create();
    $rules = new PayrollRules;

    $monday = CarbonImmutable::parse('2026-07-13'); // Monday

    expect($rules->baseOrdinaryHoursFor($company, $monday))->toBe(8.0);
});

test('base ordinary hours for saturday is four', function () {
    $company = Company::factory()->create();
    $rules = new PayrollRules;

    $saturday = CarbonImmutable::parse('2026-07-18'); // Saturday

    expect($rules->baseOrdinaryHoursFor($company, $saturday))->toBe(4.0);
});

test('base ordinary hours for sunday is zero', function () {
    $company = Company::factory()->create();
    $rules = new PayrollRules;

    $sunday = CarbonImmutable::parse('2026-07-19'); // Sunday

    expect($rules->baseOrdinaryHoursFor($company, $sunday))->toBe(0.0);
});
