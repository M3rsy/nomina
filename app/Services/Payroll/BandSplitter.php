<?php

namespace App\Services\Payroll;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure service that splits a worked time span into Costa/Honduras recargo bands.
 *
 * Bands are evaluated per calendar day using half-open intervals:
 *   00:00-06:00 -> extra 75%
 *   06:00-14:00 -> ordinary
 *   14:00-18:00 -> extra 25%
 *   18:00-00:00 -> extra 50%
 *
 * A span that crosses midnight is split at 00:00; the post-midnight portion
 * belongs to the next day's 00:00-06:00 band.
 */
class BandSplitter
{
    /**
     * Band definitions as minutes-from-midnight offsets [start, end).
     */
    private const BANDS = [
        'extra75' => [0, 360],      // 00:00 - 06:00
        'ordinary' => [360, 840], // 06:00 - 14:00
        'extra25' => [840, 1080], // 14:00 - 18:00
        'extra50' => [1080, 1440], // 18:00 - 24:00
    ];

    public function split(CarbonInterface $entry, CarbonInterface $exit): BandSplit
    {
        $entry = CarbonImmutable::parse($entry);
        $exit = CarbonImmutable::parse($exit);

        if ($exit <= $entry) {
            return new BandSplit;
        }

        $totals = [
            'ordinary' => 0.0,
            'extra25' => 0.0,
            'extra50' => 0.0,
            'extra75' => 0.0,
        ];

        $day = $entry->startOfDay();
        $lastDay = $exit->startOfDay();

        while ($day->lte($lastDay)) {
            $dayStart = $day;
            $dayEnd = $day->copy()->addDay();

            $segmentStart = $entry->max($dayStart);
            $segmentEnd = $exit->min($dayEnd);

            if ($segmentStart < $segmentEnd) {
                foreach (self::BANDS as $band => [$startMinutes, $endMinutes]) {
                    $bandStart = $day->copy()->addMinutes($startMinutes);
                    $bandEnd = $day->copy()->addMinutes($endMinutes);

                    $overlapStart = $segmentStart->max($bandStart);
                    $overlapEnd = $segmentEnd->min($bandEnd);

                    if ($overlapStart < $overlapEnd) {
                        $totals[$band] += $overlapStart->floatDiffInMinutes($overlapEnd);
                    }
                }
            }

            $day = $day->addDay();
        }

        return new BandSplit(
            ordinaryMinutes: $totals['ordinary'],
            extra25Minutes: $totals['extra25'],
            extra50Minutes: $totals['extra50'],
            extra75Minutes: $totals['extra75'],
        );
    }
}
