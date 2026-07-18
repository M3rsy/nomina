<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRevision>
 */
class EmployeeRevisionFactory extends Factory
{
    protected $model = EmployeeRevision::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'user_id' => User::factory(),
            'field' => fake()->randomElement(['dni', 'expected_salary', 'job_title']),
            'old_value' => (string) fake()->word(),
            'new_value' => (string) fake()->word(),
            'reason' => fake()->optional()->sentence(),
        ];
    }

    public function forEmployee(?Employee $employee = null): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee ? $employee->id : Employee::factory(),
        ]);
    }
}
