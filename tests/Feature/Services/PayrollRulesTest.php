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

test('overtime bands ignore historical per-schedule configuration', function () {
    $rules = new PayrollRules;

    $bands = $rules->normalizedOvertimeBands([
        ['start' => '00:00', 'end' => '24:00', 'rate' => 100],
    ]);

    expect($bands)->toBeArray()
        ->and($bands)->toHaveCount(4)
        ->and($bands[0])->toMatchArray(['start' => 0, 'end' => 360, 'bucket' => 'extra75', 'extra_percent' => 75])
        ->and($bands[1])->toMatchArray(['start' => 360, 'end' => 840, 'bucket' => 'ordinary', 'extra_percent' => 0])
        ->and($bands[2])->toMatchArray(['start' => 840, 'end' => 1080, 'bucket' => 'extra25', 'extra_percent' => 25])
        ->and($bands[3])->toMatchArray(['start' => 1080, 'end' => 1440, 'bucket' => 'extra50', 'extra_percent' => 50]);
});

test('overtime bands remain canonical across repeated calls and caller mutation', function () {
    $rules = new PayrollRules;
    $customBands = [
        ['start' => '00:00', 'end' => '24:00', 'rate' => 100],
    ];

    $first = $rules->normalizedOvertimeBands($customBands);
    $first[0]['extra_percent'] = 100;
    $second = $rules->normalizedOvertimeBands(null);
    $third = $rules->normalizedOvertimeBands($customBands);

    expect($second)->toBe($third)
        ->and($second[0])->toMatchArray([
            'start' => 0,
            'end' => 360,
            'bucket' => 'extra75',
            'extra_percent' => 75,
        ]);
});

test('canonical global rate bands always provide complete coverage', function () {
    expect((new PayrollRules)->hasCompleteRateBandCoverage([
        ['start' => '06:00', 'end' => '12:00', 'rate' => 0],
    ]))->toBeTrue();
});
