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

    /**
     * Default banding template used when there is no custom schedule config.
     * Percent is expressed as overtime percentage over ordinary.
     */
    private const DEFAULT_BANDS = [
        ['start' => self::BAND_EXTRA_75_START, 'end' => self::BAND_EXTRA_75_END, 'extra_percent' => 75],
        ['start' => self::BAND_ORDINARY_START, 'end' => self::BAND_ORDINARY_END, 'extra_percent' => 0],
        ['start' => self::BAND_EXTRA_25_START, 'end' => self::BAND_EXTRA_25_END, 'extra_percent' => 25],
        ['start' => self::BAND_EXTRA_50_START, 'end' => self::BAND_EXTRA_50_END, 'extra_percent' => 50],
    ];

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

    /**
     * Returns normalized overtime bands for payroll calculation.
     *
     * @return array<int, array{start:int,end:int,bucket:string,extra_percent:int}>
     */
    public function overtimeBandsFor(Company $company, int $dayOfWeek): array
    {
        $schedule = $this->getWorkSchedule($company, $dayOfWeek);

        return $this->normalizedOvertimeBands($schedule->banding_json);
    }

    /**
     * Normalize bands with a resilient parser.
     *
     * Supported input shape:
     * - [ ['start' => '06:00', 'end' => '14:00', 'extra_percent' => 0], ... ]
     * - [ ['start' => 360, 'end' => 840, 'percent' => '25'], ... ]
     * - [ 'bands' => [ ... ] ]
     */
    public function normalizedOvertimeBands(mixed $rawBands): array
    {
        $source = $this->extractBandList($rawBands);

        if (empty($source)) {
            return $this->defaultOvertimeBands();
        }

        $bands = [];

        foreach ($source as $band) {
            if (! is_array($band)) {
                return $this->defaultOvertimeBands();
            }

            $start = $this->parseBandBoundary($band['start'] ?? $band['start_minutes'] ?? $band['from'] ?? null);
            $end = $this->parseBandBoundary($band['end'] ?? $band['end_minutes'] ?? $band['to'] ?? null);

            if ($start === null || $end === null) {
                return $this->defaultOvertimeBands();
            }

            $percent = $this->parseBandPercent(
                $band['extra_percent']
                ?? $band['percent']
                ?? $band['rate']
                ?? $band['extra']
                ?? $band['extra_rate']
                ?? null,
            );

            if ($percent === null) {
                $percent = $this->inferPercentFromLabel((string) ($band['label'] ?? $band['name'] ?? ''));
            }

            $bucket = $this->bucketFromPercent($percent);
            if ($bucket === null) {
                return $this->defaultOvertimeBands();
            }

            if ($end <= $start) {
                $end += 1440;
            }

            $bands[] = [
                'start' => $start,
                'end' => $end,
                'bucket' => $bucket,
                'extra_percent' => $percent,
            ];
        }

        if (empty($bands)) {
            return $this->defaultOvertimeBands();
        }

        usort(
            $bands,
            static fn (array $a, array $b): int => $a['start'] <=> $b['start']
                ?: $a['end'] <=> $b['end'],
        );

        return $bands;
    }

    private function extractBandList(mixed $rawBands): array
    {
        if (! is_array($rawBands)) {
            return [];
        }

        if (array_is_list($rawBands)) {
            return $rawBands;
        }

        return is_array($rawBands['bands'] ?? null) ? $rawBands['bands'] : [];
    }

    private function parseBandBoundary(mixed $value): ?int
    {
        if (is_int($value)) {
            return $this->validateMinuteValue($value);
        }

        if (is_float($value)) {
            return $this->validateMinuteValue((int) round($value));
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return $this->validateMinuteValue((int) round((float) $value));
        }

        if (! preg_match('/^(\d{1,2}):([0-5]\d)$/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour === 24 && $minute === 0) {
            return 1440;
        }

        if ($hour > 23) {
            return null;
        }

        return $this->validateMinuteValue($hour * 60 + $minute);
    }

    private function validateMinuteValue(int $value): ?int
    {
        return ($value >= 0 && $value <= 1440) ? $value : null;
    }

    private function parseBandPercent(mixed $value): ?int
    {
        if (is_int($value)) {
            return in_array($value, [0, 25, 50, 75, 100], true) ? $value : null;
        }

        if (is_float($value)) {
            $value = (int) round($value);

            return in_array($value, [0, 25, 50, 75, 100], true) ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        $normalized = rtrim($normalized, " \t\n\r\0\x0B%");

        if (! is_numeric($normalized)) {
            return null;
        }

        $value = (int) round((float) $normalized);

        return in_array($value, [0, 25, 50, 75, 100], true) ? $value : null;
    }

    private function inferPercentFromLabel(string $label): ?int
    {
        $label = strtolower($label);

        if ($label === '') {
            return null;
        }

        if (str_contains($label, 'ordinary') || str_contains($label, 'normal')) {
            return 0;
        }

        if (str_contains($label, '75')) {
            return 75;
        }

        if (str_contains($label, '50')) {
            return 50;
        }

        if (str_contains($label, '25')) {
            return 25;
        }

        return null;
    }

    private function bucketFromPercent(int $percent): ?string
    {
        return match ($percent) {
            0 => 'ordinary',
            25 => 'extra25',
            50 => 'extra50',
            75 => 'extra75',
            100 => 'extra100',
            default => null,
        };
    }

    private function defaultOvertimeBands(): array
    {
        return $this->normalizedOvertimeBands(self::DEFAULT_BANDS);
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
