<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

class PayrollRules
{
    public const DAY_SUNDAY = 0;
    public const DAY_MONDAY = 1;
    public const DAY_TUESDAY = 2;
    public const DAY_WEDNESDAY = 3;
    public const DAY_THURSDAY = 4;
    public const DAY_FRIDAY = 5;
    public const DAY_SATURDAY = 6;

    public const BAND_ORDINARY_START = '06:00';
    public const BAND_ORDINARY_END = '14:00';

    public const BAND_EXTRA_25_START = '14:00';
    public const BAND_EXTRA_25_END = '18:00';

    public const BAND_EXTRA_50_START = '18:00';
    public const BAND_EXTRA_50_END = '00:00';

    public const BAND_EXTRA_75_START = '00:00';
    public const BAND_EXTRA_75_END = '06:00';

    public function getWorkSchedule(Company $company, int $dayOfWeek): WorkSchedule
    {
        $schedule = WorkSchedule::withoutCompanyScope()
            ->where('company_id', $company->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if ($schedule === null) {
            $schedule = $this->defaultWorkScheduleForDay($company, $dayOfWeek);
        }

        return $schedule;
    }

    public function isHoliday(Company $company, CarbonImmutable $date): bool
    {
        return Holiday::withoutCompanyScope()
            ->where('company_id', $company->id)
            ->whereDate('date', $date->toDateString())
            ->where('is_active', true)
            ->exists();
    }

    public function baseOrdinaryHoursFor(Company $company, CarbonImmutable $date): float
    {
        $schedule = $this->getWorkSchedule($company, $date->dayOfWeek);

        return $schedule->is_working_day ? (float) $schedule->base_ordinary_hours : 0.0;
    }

    private function defaultWorkScheduleForDay(Company $company, int $dayOfWeek): WorkSchedule
    {
        $defaults = collect(Company::defaultWorkSchedules())->keyBy('day_of_week');

        $default = $defaults[$dayOfWeek] ?? [
            'day_of_week' => $dayOfWeek,
            'is_working_day' => true,
            'base_ordinary_hours' => 8.00,
            'notes' => null,
        ];

        return new WorkSchedule([
            'company_id' => $company->id,
            ...$default,
        ]);
    }
}
