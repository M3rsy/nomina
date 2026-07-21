<?php

use App\Models\Company;
use App\Models\Holiday;
use App\Services\PayrollRules;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionRoleSeeder;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
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

test('overtime bands fallback to defaults when no config is provided', function () {
    $rules = new PayrollRules;

    $bands = $rules->normalizedOvertimeBands(null);

    expect($bands)->toBeArray()
        ->and($bands)->toHaveCount(4)
        ->and($bands[0]['start'])->toBe(0)
        ->and($bands[0]['bucket'])->toBe('extra75')
        ->and($bands[1]['start'])->toBe(360)
        ->and($bands[1]['bucket'])->toBe('ordinary');
});

test('overtime bands normalize configured schedule JSON', function () {
    $rules = new PayrollRules;

    $bands = $rules->normalizedOvertimeBands([
        ['start' => '08:00', 'end' => '12:00', 'rate' => 25],
        ['start' => '12:00', 'end' => '20:00', 'rate' => 50],
    ]);

    expect($bands)->toBeArray()
        ->and($bands)->toHaveCount(2)
        ->and($bands[0]['start'])->toBe(480)
        ->and($bands[0]['bucket'])->toBe('extra25')
        ->and($bands[1]['start'])->toBe(720)
        ->and($bands[1]['bucket'])->toBe('extra50');
});
