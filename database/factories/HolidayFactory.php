<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'date' => fake()->date(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function forCompany(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company ? $company->id : Company::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
