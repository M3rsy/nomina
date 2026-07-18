<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WorkSchedule;
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
            'notes' => null,
        ];
    }

    public function forCompany(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company ? $company->id : Company::factory(),
        ]);
    }
}
