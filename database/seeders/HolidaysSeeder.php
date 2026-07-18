<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Holiday;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class HolidaysSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $companyA = Company::query()->firstWhere('slug', 'empresa-a');

        if ($companyA === null) {
            return;
        }

        Holiday::withoutCompanyScope()->firstOrCreate(
            [
                'company_id' => $companyA->id,
                'date' => now()->startOfYear()->addMonths(8)->setDay(15)->toDateString(),
            ],
            [
                'company_id' => $companyA->id,
                'name' => 'Día de la independencia',
                'description' => 'Feriado nacional de Honduras',
                'is_active' => true,
            ]
        );
    }
}
