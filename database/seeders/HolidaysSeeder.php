<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Holiday;
use App\Services\Attendance\HolidayCalendar;
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

        $date = now()->startOfYear()->addMonths(8)->setDay(15)->toDateString();

        if (! Holiday::withoutCompanyScope()->where('company_id', $companyA->id)->whereDate('date', $date)->exists()) {
            app(HolidayCalendar::class)->save($companyA, null, [
                'date' => $date,
                'name' => 'Día de la independencia',
                'description' => 'Feriado nacional de Honduras',
                'is_active' => true,
            ]);
        }
    }
}
