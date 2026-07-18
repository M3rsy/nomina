<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawMark>
 */
class RawMarkFactory extends Factory
{
    protected $model = RawMark::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'pay_period_id' => fn (array $attributes) => PayPeriod::factory()->forCompany(Company::find($attributes['company_id'])),
            'uploaded_file_id' => fn (array $attributes) => UploadedFile::factory()->forCompany(Company::find($attributes['company_id']))->forPayPeriod(PayPeriod::find($attributes['pay_period_id'])),
            'employee_external_id' => (string) fake()->randomNumber(5, true),
            'employee_id' => null,
            'event_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'raw_line' => fake()->text(100),
            'source' => fake()->randomElement(['glg', 'attlog']),
            'row_number' => fake()->unique()->numberBetween(1, 10000),
            'status' => 'pending',
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

    public function forPayPeriod(?PayPeriod $payPeriod = null): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_period_id' => $payPeriod ? $payPeriod->id : PayPeriod::factory(),
        ]);
    }

    public function forUploadedFile(?UploadedFile $uploadedFile = null): static
    {
        return $this->state(fn (array $attributes) => [
            'uploaded_file_id' => $uploadedFile ? $uploadedFile->id : UploadedFile::factory(),
        ]);
    }

    public function forEmployee(?Employee $employee = null): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employee ? $employee->id : null,
        ]);
    }
}
