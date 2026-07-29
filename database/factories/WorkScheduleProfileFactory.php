<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WorkScheduleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkScheduleProfile>
 */
class WorkScheduleProfileFactory extends Factory
{
    protected $model = WorkScheduleProfile::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'profile_key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'version' => 1,
            'is_active' => true,
            'created_by' => null,
            'change_reason' => null,
            'retired_at' => null,
            'retired_by' => null,
            'retirement_reason' => null,
            'replacement_profile_id' => null,
        ];
    }

    public function forCompany(?Company $company = null): static
    {
        return $this->state(fn () => [
            'company_id' => $company?->id ?? Company::factory(),
        ]);
    }
}
