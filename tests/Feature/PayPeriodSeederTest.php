<?php

use App\Models\Company;
use App\Models\PayPeriod;
use Database\Seeders\PayPeriodSeeder;

test('pay period seeder creates empresa-a january period', function () {
    $this->seed(PayPeriodSeeder::class);

    $company = Company::where('slug', 'empresa-a')->first();
    expect($company)->not->toBeNull();

    $period = PayPeriod::where('company_id', $company->id)
        ->where('slug', 'nomina-enero-2026')
        ->first();

    expect($period)->not->toBeNull();
    expect($period->name)->toBe('Nómina enero 2026');
    expect($period->start_date->toDateString())->toBe('2026-01-01');
    expect($period->end_date->toDateString())->toBe('2026-01-31');
    expect($period->status)->toBe('draft');
});

test('pay period seeder is idempotent', function () {
    $this->seed(PayPeriodSeeder::class);
    $this->seed(PayPeriodSeeder::class);

    $company = Company::where('slug', 'empresa-a')->first();
    expect(PayPeriod::where('company_id', $company->id)->where('slug', 'nomina-enero-2026')->count())->toBe(1);
});
