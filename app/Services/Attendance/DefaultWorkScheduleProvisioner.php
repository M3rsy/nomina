<?php

namespace App\Services\Attendance;

use App\Models\Company;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use Illuminate\Support\Facades\DB;

class DefaultWorkScheduleProvisioner
{
    public function provision(Company $company): WorkScheduleProfile
    {
        return DB::transaction(function () use ($company): WorkScheduleProfile {
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

            if (! $profile->wasRecentlyCreated) {
                return $profile;
            }

            foreach (Company::defaultWorkSchedules() as $schedule) {
                WorkSchedule::withoutCompanyScope()->create([
                    'work_schedule_profile_id' => $profile->id,
                    'day_of_week' => $schedule['day_of_week'],
                    'company_id' => $company->id,
                    'is_working_day' => $schedule['is_working_day'],
                    'base_ordinary_hours' => $schedule['base_ordinary_hours'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'notes' => $schedule['notes'],
                ]);
            }

            return $profile;
        });
    }
}
