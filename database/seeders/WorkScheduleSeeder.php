<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\WorkSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorkScheduleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (Company::all() as $company) {
            foreach (Company::defaultWorkSchedules() as $schedule) {
                WorkSchedule::withoutCompanyScope()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'day_of_week' => $schedule['day_of_week'],
                    ],
                    [
                        'company_id' => $company->id,
                        'is_working_day' => $schedule['is_working_day'],
                        'base_ordinary_hours' => $schedule['base_ordinary_hours'],
                        'notes' => $schedule['notes'],
                    ]
                );
            }
        }
    }
}
