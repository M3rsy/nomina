<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollResult>
 */
class PayrollResultFactory extends Factory
{
    protected $model = PayrollResult::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'pay_period_id' => fn (array $attributes) => PayPeriod::factory()->forCompany(Company::find($attributes['company_id'])),
            'employee_id' => fn (array $attributes) => Employee::factory()->forCompany(Company::find($attributes['company_id'])),
            'date' => fake()->date(),
            'entry_at' => null,
            'exit_at' => null,
            'worked_hours' => 0.0,
            'ordinary_hours' => 0.0,
            'extra_25_hours' => 0,
            'extra_50_hours' => 0,
            'extra_75_hours' => 0,
            'extra_100_hours' => 0,
            'worked_minutes' => 0,
            'scheduled_minutes' => 0,
            'recognized_minutes' => 0,
            'detected_overtime_minutes' => 0,
            'approved_overtime_minutes' => 0,
            'ordinary_minutes' => 0,
            'extra_25_minutes' => 0,
            'extra_50_minutes' => 0,
            'extra_75_minutes' => 0,
            'extra_100_minutes' => 0,
            'is_absence' => false,
            'is_justified' => false,
            'unjustified' => false,
            'notes' => fake()->optional()->sentence(),
            'rules_version' => '1',
            'metadata' => null,
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
