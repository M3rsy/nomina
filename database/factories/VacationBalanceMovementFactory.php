<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\VacationBalanceMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VacationBalanceMovement> */
class VacationBalanceMovementFactory extends Factory
{
    protected $model = VacationBalanceMovement::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create(['company_id' => $attributes['company_id']]),
            'type' => VacationBalanceMovement::MANUAL_ADJUSTMENT,
            'days' => fake()->randomElement([-5, 5, 10]),
            'reason' => fake()->sentence(),
            'recorded_by' => null,
        ];
    }
}
