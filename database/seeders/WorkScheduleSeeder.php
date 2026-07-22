<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorkScheduleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (Company::all() as $company) {
            $profile = WorkScheduleProfile::withoutCompanyScope()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'profile_key' => 'general',
                    'version' => 1,
                ],
                [
                    'name' => 'Jornada general',
                    'is_active' => true,
                ],
            );

            foreach (Company::defaultWorkSchedules() as $schedule) {
                WorkSchedule::withoutCompanyScope()->updateOrCreate(
                    [
                        'work_schedule_profile_id' => $profile->id,
                        'day_of_week' => $schedule['day_of_week'],
                    ],
                    [
                        'company_id' => $company->id,
                        'is_working_day' => $schedule['is_working_day'],
                        'base_ordinary_hours' => $schedule['base_ordinary_hours'],
                        'start_time' => $schedule['start_time'],
                        'end_time' => $schedule['end_time'],
                        'notes' => $schedule['notes'],
                    ]
                );
            }
        }
    }
}
