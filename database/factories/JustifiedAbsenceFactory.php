<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\JustifiedAbsence;
use App\Models\PayPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JustifiedAbsence>
 */
class JustifiedAbsenceFactory extends Factory
{
    protected $model = JustifiedAbsence::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'pay_period_id' => fn (array $attributes) => PayPeriod::factory()->forCompany(Company::find($attributes['company_id'])),
            'employee_id' => fn (array $attributes) => Employee::factory()->forCompany(Company::find($attributes['company_id'])),
            'date' => fake()->date(),
            'reason' => fake()->randomElement(['holiday', 'permission', 'day_off', 'other']),
            'notes' => fake()->optional()->sentence(),
            'justified_by' => User::factory(),
        ];
    }

    public function forCompany(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company ? $company->id : Company::factory(),
        ]);
    }

    public function forPayPeriod(?PayPeriod $payPeriod = null): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_period_id' => $payPeriod ? $payPeriod->id : PayPeriod::factory(),
        ]);
    }

    public function forEmployee(?Employee $employee = null): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee ? $employee->id : Employee::factory(),
        ]);
    }
}
