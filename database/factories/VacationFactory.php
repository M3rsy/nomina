<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vacation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vacation> */
class VacationFactory extends Factory
{
    protected $model = Vacation::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', '+1 month');

        return [
            'company_id' => Company::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create(['company_id' => $attributes['company_id']]),
            'start_date' => $start,
            'end_date' => (clone $start)->modify('+5 days'),
            'status' => Vacation::APPROVED,
            'notes' => fake()->optional()->sentence(),
            'excluded_dates' => [],
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ];
    }
}
