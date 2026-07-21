<?php

use App\Models\Company;
use App\Models\Holiday;
use App\Models\WorkSchedule;
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

test('overtime bands fallback to defaults when company schedule has no config', function () {
    $company = Company::factory()->create();
    $rules = new PayrollRules;
    $monday = CarbonImmutable::parse('2026-07-13');

    $bands = $rules->overtimeBandsFor($company, $monday->dayOfWeek);

    expect($bands)->toBeArray()
        ->and($bands)->toHaveCount(4)
        ->and($bands[0]['start'])->toBe(0)
        ->and($bands[0]['bucket'])->toBe('extra75')
        ->and($bands[1]['start'])->toBe(360)
        ->and($bands[1]['bucket'])->toBe('ordinary');
});

test('overtime bands use schedule JSON when available', function () {
    $company = Company::factory()->create();
    $rules = new PayrollRules;
    $date = CarbonImmutable::parse('2026-07-13'); // Monday

    WorkSchedule::withoutCompanyScope()->create([
        'company_id' => $company->id,
        'day_of_week' => $date->dayOfWeek,
        'is_working_day' => true,
        'base_ordinary_hours' => 8.00,
        'banding_json' => [
            ['start' => '08:00', 'end' => '12:00', 'rate' => 25],
            ['start' => '12:00', 'end' => '20:00', 'rate' => 50],
        ],
        'notes' => null,
    ]);

    $bands = $rules->overtimeBandsFor($company, $date->dayOfWeek);

    expect($bands)->toBeArray()
        ->and($bands)->toHaveCount(2)
        ->and($bands[0]['start'])->toBe(480)
        ->and($bands[0]['bucket'])->toBe('extra25')
        ->and($bands[1]['start'])->toBe(720)
        ->and($bands[1]['bucket'])->toBe('extra50');
});
