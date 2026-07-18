<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PayPeriod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PayPeriodSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $companyA = Company::firstOrCreate(
            ['slug' => 'empresa-a'],
            ['name' => 'Empresa A', 'slug' => 'empresa-a', 'legal_id' => 'RTN-A-001', 'is_active' => true]
        );

        PayPeriod::firstOrCreate(
            ['company_id' => $companyA->id, 'slug' => 'nomina-enero-2026'],
            [
                'company_id' => $companyA->id,
                'slug' => 'nomina-enero-2026',
                'name' => 'Nómina enero 2026',
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
                'status' => 'draft',
            ]
        );
    }
}
