<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePositionAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeePositionAssignment>
 */
class EmployeePositionAssignmentFactory extends Factory
{
    protected $model = EmployeePositionAssignment::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'company_id' => $attributes['company_id'],
            ]),
            'title' => fake()->jobTitle(),
            'effective_from' => fake()->date(),
            'effective_to' => null,
            'assigned_by' => null,
            'reason' => fake()->sentence(),
        ];
    }
}
