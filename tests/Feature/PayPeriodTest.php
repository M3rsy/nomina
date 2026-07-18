<?php

use App\Models\Company;
use App\Models\PayPeriod;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;

test('pay period belongs to a company and can be created', function () {
    $company = Company::factory()->create();

    $period = PayPeriod::create([
        'company_id' => $company->id,
        'slug' => 'nomina-enero-2026',
        'name' => 'Nómina enero 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);

    expect($period->company_id)->toBe($company->id);
    expect($period->slug)->toBe('nomina-enero-2026');
    expect($period->start_date->toDateString())->toBe('2026-01-01');
    expect($period->end_date->toDateString())->toBe('2026-01-31');
    expect($period->status)->toBe('draft');
    expect($period->isActive())->toBeTrue();
    expect($period->canUploadFiles())->toBeTrue();
});

test('pay period scope is isolated by current company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    PayPeriod::create([
        'company_id' => $companyA->id,
        'slug' => 'nomina-enero-2026',
        'name' => 'Nómina enero A',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);

    PayPeriod::create([
        'company_id' => $companyB->id,
        'slug' => 'nomina-enero-2026',
        'name' => 'Nómina enero B',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);

    app(CurrentCompany::class)->set($companyA);
    expect(PayPeriod::count())->toBe(1);
    expect(PayPeriod::first()->name)->toBe('Nómina enero A');

    app(CurrentCompany::class)->set($companyB);
    expect(PayPeriod::count())->toBe(1);
    expect(PayPeriod::first()->name)->toBe('Nómina enero B');
});

test('pay period slug is unique per company', function () {
    $company = Company::factory()->create();

    PayPeriod::create([
        'company_id' => $company->id,
        'slug' => 'nomina-enero-2026',
        'name' => 'Nómina enero 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);

    expect(fn () => PayPeriod::create([
        'company_id' => $company->id,
        'slug' => 'nomina-enero-2026',
        'name' => 'Nómina enero 2026 bis',
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
        'status' => 'draft',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
