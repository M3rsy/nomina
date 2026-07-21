<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    protected $model = WorkSchedule::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'is_working_day' => true,
            'base_ordinary_hours' => 8.00,
            'start_time' => '06:00',
            'end_time' => '14:00',
            'banding_json' => null,
            'notes' => null,
        ];
    }

    public function forCompany(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company ? $company->id : Company::factory(),
        ]);
    }

    public function forProfile(WorkScheduleProfile $profile): static
    {
        return $this->state(fn () => [
            'company_id' => $profile->company_id,
            'work_schedule_profile_id' => $profile->id,
        ]);
    }
}
