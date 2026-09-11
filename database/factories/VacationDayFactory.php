<?php

namespace Database\Factories;

use App\Models\Vacation;
use App\Models\VacationDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VacationDay> */
class VacationDayFactory extends Factory
{
    protected $model = VacationDay::class;

    public function definition(): array
    {
        return [
            'vacation_id' => Vacation::factory(),
            'company_id' => fn (array $attributes) => Vacation::find($attributes['vacation_id'])->company_id,
            'employee_id' => fn (array $attributes) => Vacation::find($attributes['vacation_id'])->employee_id,
            'work_date' => fake()->date(),
            'scheduled_start' => fake()->dateTime(),
            'scheduled_end' => fn (array $attributes) => (clone $attributes['scheduled_start'])->modify('+8 hours'),
            'planned_minutes' => 480,
            'rate_minutes' => ['ordinary' => 480, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
            'snapshot_fingerprint' => hash('sha256', fake()->uuid()),
            'holiday_generation' => 0,
            'active_marker' => true,
        ];
    }
}
