<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkScheduleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeScheduleAssignment>
 */
class EmployeeScheduleAssignmentFactory extends Factory
{
    protected $model = EmployeeScheduleAssignment::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'company_id' => $attributes['company_id'],
            ]),
            'work_schedule_profile_id' => fn (array $attributes) => WorkScheduleProfile::factory()->create([
                'company_id' => $attributes['company_id'],
            ]),
            'effective_from' => fake()->date(),
            'effective_to' => null,
            'assigned_by' => null,
            'reason' => fake()->sentence(),
        ];
    }
}
