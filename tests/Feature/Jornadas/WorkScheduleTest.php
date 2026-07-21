<?php

use App\Models\Company;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\CurrentCompany;
use Database\Seeders\WorkScheduleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionRoleSeeder::class);
});

test('company has seven default work schedules after seeding', function () {
    $company = Company::factory()->create();

    $this->seed(WorkScheduleSeeder::class);

    expect(WorkSchedule::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(7);
});

test('default schedules match business rules', function () {
    $company = Company::factory()->create();

    $this->seed(WorkScheduleSeeder::class);

    $schedules = WorkSchedule::withoutCompanyScope()
        ->where('company_id', $company->id)
        ->get()
        ->keyBy('day_of_week');

    foreach ([1, 2, 3, 4, 5] as $day) {
        expect($schedules[$day]->is_working_day)->toBeTrue()
            ->and((float) $schedules[$day]->base_ordinary_hours)->toBe(8.00);
    }

    expect($schedules[6]->is_working_day)->toBeTrue()
        ->and((float) $schedules[6]->base_ordinary_hours)->toBe(4.00);

    expect($schedules[0]->is_working_day)->toBeFalse()
        ->and((float) $schedules[0]->base_ordinary_hours)->toBe(0.00);
});

test('work schedules are scoped per company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    WorkSchedule::factory()->forCompany($companyA)->create(['day_of_week' => 1]);
    WorkSchedule::factory()->forCompany($companyB)->create(['day_of_week' => 1]);

    app(CurrentCompany::class)->set($companyA);

    expect(WorkSchedule::count())->toBe(1)
        ->and(WorkSchedule::first()->company_id)->toBe($companyA->id);
});

test('updating a work schedule persists', function () {
    $company = Company::factory()->create();
    $schedule = WorkSchedule::factory()->forCompany($company)->create([
        'day_of_week' => 1,
        'is_working_day' => true,
        'base_ordinary_hours' => 8.00,
    ]);

    $schedule->update([
        'is_working_day' => false,
        'base_ordinary_hours' => 6.00,
        'notes' => 'Ajuste especial',
    ]);

    $schedule->refresh();

    expect($schedule->is_working_day)->toBeFalse()
        ->and((float) $schedule->base_ordinary_hours)->toBe(6.00)
        ->and($schedule->notes)->toBe('Ajuste especial');
});

test('work schedule can persist configurable banding json', function () {
    $company = Company::factory()->create();
    $schedule = WorkSchedule::factory()->forCompany($company)->create([
        'day_of_week' => 2,
        'is_working_day' => true,
        'base_ordinary_hours' => 8.00,
    ]);

    $schedule->update([
        'banding_json' => [
            ['start' => '06:00', 'end' => '14:00', 'extra_percent' => 0],
            ['start' => '14:00', 'end' => '18:00', 'extra_percent' => 25],
            ['start' => '18:00', 'end' => '00:00', 'extra_percent' => 50],
            ['start' => '00:00', 'end' => '06:00', 'extra_percent' => 75],
        ],
        'notes' => 'Pro bands',
    ]);

    $schedule->refresh();

    expect($schedule->banding_json)->toBeArray()
        ->and($schedule->banding_json[0]['extra_percent'])->toBe(0)
        ->and($schedule->notes)->toBe('Pro bands');
});
