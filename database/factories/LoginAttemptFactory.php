<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginAttempt>
 */
class LoginAttemptFactory extends Factory
{
    protected $model = LoginAttempt::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'email' => fake()->safeEmail(),
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'success' => true,
        ];
    }

    public function forCompany(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company ? $company->id : Company::factory(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'success' => false,
        ]);
    }
}
