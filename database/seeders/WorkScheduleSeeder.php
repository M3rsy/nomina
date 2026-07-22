<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Attendance\DefaultWorkScheduleProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorkScheduleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(DefaultWorkScheduleProvisioner $scheduleProvisioner): void
    {
        foreach (Company::all() as $company) {
            $scheduleProvisioner->provision($company);
        }
    }
}
