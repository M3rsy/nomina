<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayPeriod>
 */
class PayPeriodFactory extends Factory
{
    protected $model = PayPeriod::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 year', 'now');
        $end = (clone $start)->modify('+14 days');

        return [
            'company_id' => Company::factory(),
            'slug' => 'nomina-'.fake()->unique()->slug(2),
            'name' => 'Nómina '.fake()->monthName(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'status' => 'draft',
            'notes' => null,
            'metadata' => null,
        ];
    }

    public function forCompany(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company ? $company->id : Company::factory(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
