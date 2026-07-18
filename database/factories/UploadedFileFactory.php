<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UploadedFile>
 */
class UploadedFileFactory extends Factory
{
    protected $model = UploadedFile::class;

    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'pay_period_id' => fn (array $attributes) => PayPeriod::factory()->forCompany(Company::find($attributes['company_id'])),
            'original_name' => fake()->filePath(),
            'stored_name' => fake()->uuid().'.txt',
            'disk' => 'local',
            'path' => 'uploads/'.fake()->uuid(),
            'mime' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => fake()->numberBetween(100, 100000),
            'encoding' => 'ASCII',
            'sha256' => fake()->sha256(),
            'status' => 'pending',
            'user_id' => User::factory(),
            'validation_summary' => null,
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
}
