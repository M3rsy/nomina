<?php

namespace Database\Factories;

use App\Models\AttendanceException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceException>
 */
class AttendanceExceptionFactory extends Factory
{
    protected $model = AttendanceException::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', 'now');
        $end = (clone $start)->modify('+15 minutes');

        return [
            'company_id' => Company::factory(),
            'pay_period_id' => fn (array $attributes) => PayPeriod::factory()->forCompany(Company::find($attributes['company_id'])),
            'employee_id' => fn (array $attributes) => Employee::factory()->forCompany(Company::find($attributes['company_id'])),
            'work_date' => $start->format('Y-m-d'),
            'deficit_key' => hash('sha256', fake()->uuid()),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'segment_kind' => 'late_arrival',
            'starts_at' => $start,
            'ends_at' => $end,
            'minutes' => 15,
            'rate_minutes' => [
                'ordinary' => 15,
                'extra25' => 0,
                'extra50' => 0,
                'extra75' => 0,
                'extra100' => 0,
            ],
            'decision' => AttendanceException::GRANTED,
            'reason' => fake()->sentence(),
            'decided_by' => fn (array $attributes) => User::factory()->forCompany(Company::find($attributes['company_id'])),
            'supersedes_id' => null,
        ];
    }
}
