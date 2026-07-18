<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $titles = [
            'Guardia de seguridad',
            'Administrador',
            'Supervisor',
            'Operador',
            'Auxiliar contable',
            'Vendedor',
            'Conductor',
            'Mecánico',
        ];

        return [
            'company_id' => Company::factory(),
            'external_id' => (string) fake()->unique()->randomNumber(5, true),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'dni' => fake()->numerify('#############'),
            'sex' => fake()->randomElement(['M', 'F']),
            'birth_date' => fake()->optional(0.7)->date(),
            'address' => fake()->optional()->streetAddress(),
            'phone' => fake()->optional()->numerify('########'),
            'job_title' => fake()->randomElement($titles),
            'expected_salary' => fake()->randomFloat(2, 8000, 30000),
            'is_active' => true,
            'hired_at' => fake()->optional(0.8)->date(),
            'notes' => fake()->optional()->sentence(),
            'metadata' => null,
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
