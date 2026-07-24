<?php

namespace App\Services\Attendance;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\PayPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class HolidayCalendar
{
    public function capture(
        Company $company,
        CarbonInterface|string $startDate,
        CarbonInterface|string|null $endDate = null,
    ): HolidayCalendarContext {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate ?? $startDate)->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('Holiday context end date must not precede its start date.');
        }

        return DB::transaction(function () use ($company, $start, $end): HolidayCalendarContext {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $activeDates = Holiday::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->where('is_active', true)
                ->pluck('date')
                ->mapWithKeys(fn (mixed $date): array => [CarbonImmutable::parse($date)->toDateString() => true]);
            $generations = DB::table('holiday_calendar_generations')
                ->where('company_id', $company->id)
                ->whereBetween('calendar_date', [$start->toDateString(), $end->toDateString()])
                ->pluck('generation', 'calendar_date');
            $dates = [];

            for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                $key = $date->toDateString();
                $dates[$key] = [
                    'is_holiday' => $activeDates->has($key),
                    'generation' => (int) ($generations[$key] ?? 0),
                ];
            }

            return new HolidayCalendarContext($dates);
        });
    }

    /** @param array{date: CarbonInterface|string, name: string, description: ?string, is_active: bool} $attributes */
    public function save(Company $company, ?Holiday $holiday, array $attributes): Holiday
    {
        $values = [
            'date' => CarbonImmutable::parse($attributes['date'])->toDateString(),
            'name' => $attributes['name'],
            'description' => $attributes['description'],
            'is_active' => $attributes['is_active'],
        ];

        return DB::transaction(function () use ($company, $holiday, $values): Holiday {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $targetQuery = Holiday::withoutCompanyScope()->where('company_id', $company->id);
            $target = $holiday === null
                ? $targetQuery->whereDate('date', $values['date'])->lockForUpdate()->first()
                : $targetQuery->whereKey($holiday->id)->lockForUpdate()->firstOrFail();
            $oldDate = $target?->date->toDateString();

            if ($target !== null) {
                $target->fill($values);

                if (! $target->isDirty()) {
                    return $target;
                }
            }

            $dates = array_values(array_unique(array_filter([$oldDate, $values['date']])));
            sort($dates);
            $this->lockAffectedPeriods($company->id, $dates);

            if ($target !== null) {
                $target->save();
            } else {
                $target = Holiday::withoutCompanyScope()->create(['company_id' => $company->id] + $values);
            }

            foreach ($dates as $date) {
                $this->advance($company->id, $date);
            }

            return $target;
        });
    }

    public function delete(Holiday $holiday): void
    {
        DB::transaction(function () use ($holiday): void {
            Company::query()->whereKey($holiday->company_id)->lockForUpdate()->firstOrFail();
            $target = Holiday::withoutCompanyScope()
                ->where('company_id', $holiday->company_id)
                ->whereKey($holiday->id)
                ->lockForUpdate()
                ->firstOrFail();
            $date = $target->date->toDateString();

            $this->lockAffectedPeriods($target->company_id, [$date]);
            $target->delete();
            $this->advance($target->company_id, $date);
        });
    }

    /** @param list<string> $dates */
    private function lockAffectedPeriods(int $companyId, array $dates): void
    {
        $periods = PayPeriod::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($dates): void {
                foreach ($dates as $date) {
                    $query->orWhere(function ($overlap) use ($date): void {
                        $overlap->whereDate('start_date', '<=', $date)
                            ->whereDate('end_date', '>=', $date);
                    });
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($periods->contains(fn (PayPeriod $period): bool => in_array(
            $period->status,
            PayPeriod::ATTENDANCE_LOCKED_STATUSES,
            true,
        ))) {
            throw ValidationException::withMessages([
                'holiday' => 'El feriado no puede cambiar porque afecta un período de nómina bloqueado.',
            ]);
        }
    }

    private function advance(int $companyId, string $date): void
    {
        DB::table('holiday_calendar_generations')->insertOrIgnore([
            'company_id' => $companyId,
            'calendar_date' => $date,
            'generation' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('holiday_calendar_generations')
            ->where('company_id', $companyId)
            ->where('calendar_date', $date)
            ->lockForUpdate()
            ->increment('generation');
    }
}
