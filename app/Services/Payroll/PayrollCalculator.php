<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\JustifiedAbsence;
use App\Models\RawMark;
use App\Services\PayrollRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pure payroll day calculator. Converts a collection of RawMarks for a single
 * employee and calendar date into a PayrollDayResult following the Costa/Honduras
 * recargo band rules.
 */
class PayrollCalculator
{
    public function __construct(
        private BandSplitter $bandSplitter,
        private PayrollRules $rules,
    ) {
    }

    /**
     * @param  Collection<int, RawMark>  $marksForDay
     */
    public function calculateForDay(
        Company $company,
        Employee $employee,
        CarbonInterface $date,
        Collection $marksForDay,
        ?JustifiedAbsence $absence = null,
    ): PayrollDayResult {
        $date = CarbonImmutable::parse($date);
        $validStatuses = ['valid', 'corrected'];

        $marks = $marksForDay
            ->filter(fn (RawMark $mark) => in_array($mark->status, $validStatuses, true))
            ->sortBy('event_at')
            ->values();

        if ($marks->isEmpty()) {
            return $this->handleEmptyDay($company, $date, $absence);
        }

        if ($marks->count() === 1) {
            return new PayrollDayResult(
                isAbsence: true,
                notes: 'Missing paired mark (single mark for day).',
                metadata: ['raw_marks_count' => 1],
            );
        }

        $entry = $marks->first()->event_at;
        $exit = $marks->last()->event_at;
        $workedHours = $entry->diffInMinutes($exit) / 60;

        if ($this->rules->isHoliday($company, $date)) {
            return $this->buildHolidayResult($entry, $exit, $workedHours);
        }

        return match ($date->dayOfWeek) {
            PayrollRules::DAY_SUNDAY => $this->buildSundayResult($entry, $exit, $workedHours),
            PayrollRules::DAY_SATURDAY => $this->buildSaturdayResult($entry, $exit, $workedHours),
            default => $this->buildWeekdayResult($entry, $exit, $workedHours),
        };
    }

    private function handleEmptyDay(Company $company, CarbonImmutable $date, ?JustifiedAbsence $absence): PayrollDayResult
    {
        $schedule = $this->rules->getWorkSchedule($company, $date->dayOfWeek);

        if (! $schedule->is_working_day) {
            return new PayrollDayResult(
                notes: 'Non-working day without marks.',
                metadata: ['skip' => true],
            );
        }

        $baseHours = $this->rules->baseOrdinaryHoursFor($company, $date);

        if ($absence !== null) {
            // Opción B: justified absence pays the base ordinary jornada.
            return new PayrollDayResult(
                workedHours: $baseHours,
                ordinaryHours: $baseHours,
                isAbsence: true,
                isJustified: true,
                notes: 'Justified absence: base ordinary hours paid.',
                metadata: ['justified_absence_id' => $absence->id],
            );
        }

        return new PayrollDayResult(
            isAbsence: true,
            unjustified: true,
            notes: 'Unjustified absence on scheduled working day.',
        );
    }

    private function buildWeekdayResult(CarbonInterface $entry, CarbonInterface $exit, float $workedHours): PayrollDayResult
    {
        $split = $this->bandSplitter->split($entry, $exit);

        return $this->buildResult(
            entry: $entry,
            exit: $exit,
            workedHours: $workedHours,
            ordinaryHours: $split->ordinaryHours(),
            raw25: $split->extra25Hours(),
            raw50: $split->extra50Hours(),
            raw75: $split->extra75Hours(),
            raw100: 0.0,
        );
    }

    private function buildSaturdayResult(CarbonInterface $entry, CarbonInterface $exit, float $workedHours): PayrollDayResult
    {
        $split = $this->bandSplitter->split($entry, $exit);

        // Saturday: first 4 hours of worked time are ordinary, the rest are extras.
        // We cap the clock-time ordinary band at 4 hours and push the excess
        // into the extra 25% bucket first (per project directive).
        $ordinaryHours = $split->ordinaryHours();
        $extra25 = $split->extra25Hours();
        $extra50 = $split->extra50Hours();
        $extra75 = $split->extra75Hours();

        if ($ordinaryHours > 4.0) {
            $excess = $ordinaryHours - 4.0;
            $ordinaryHours = 4.0;

            // Push excess into the 25% band first. If the span has no 25% band,
            // it still lands in the 25% bucket because the capped hours are
            // conceptually the first 4h of ordinary time; the remainder is paid
            // as extra 25% regardless of the exact clock time.
            $extra25 += $excess;
        }

        return $this->buildResult(
            entry: $entry,
            exit: $exit,
            workedHours: $workedHours,
            ordinaryHours: $ordinaryHours,
            raw25: $extra25,
            raw50: $extra50,
            raw75: $extra75,
            raw100: 0.0,
        );
    }

    private function buildSundayResult(CarbonInterface $entry, CarbonInterface $exit, float $workedHours): PayrollDayResult
    {
        // Sunday: all worked time goes to extra 100%.
        return $this->buildResult(
            entry: $entry,
            exit: $exit,
            workedHours: $workedHours,
            ordinaryHours: 0.0,
            raw25: 0.0,
            raw50: 0.0,
            raw75: 0.0,
            raw100: $workedHours,
        );
    }

    private function buildHolidayResult(CarbonInterface $entry, CarbonInterface $exit, float $workedHours): PayrollDayResult
    {
        // Holidays are treated like Sunday: 100% extra for all worked time.
        return $this->buildResult(
            entry: $entry,
            exit: $exit,
            workedHours: $workedHours,
            ordinaryHours: 0.0,
            raw25: 0.0,
            raw50: 0.0,
            raw75: 0.0,
            raw100: $workedHours,
        );
    }

    private function buildResult(
        CarbonInterface $entry,
        CarbonInterface $exit,
        float $workedHours,
        float $ordinaryHours,
        float $raw25,
        float $raw50,
        float $raw75,
        float $raw100,
    ): PayrollDayResult {
        // Extra bands are rounded individually to the nearest integer using
        // PHP_ROUND_HALF_UP (0.5 and above rounds up). Ordinary hours are
        // kept exact.
        return new PayrollDayResult(
            entryAt: $entry,
            exitAt: $exit,
            workedHours: $workedHours,
            ordinaryHours: $ordinaryHours,
            extra25Hours: $this->roundExtra($raw25),
            extra50Hours: $this->roundExtra($raw50),
            extra75Hours: $this->roundExtra($raw75),
            extra100Hours: $this->roundExtra($raw100),
            metadata: [
                'raw_split' => [
                    'ordinary' => $ordinaryHours,
                    'extra_25' => $raw25,
                    'extra_50' => $raw50,
                    'extra_75' => $raw75,
                    'extra_100' => $raw100,
                ],
            ],
        );
    }

    private function roundExtra(float $hours): int
    {
        return (int) round($hours, 0, PHP_ROUND_HALF_UP);
    }
}
