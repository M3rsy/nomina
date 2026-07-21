<?php

namespace App\Services\Payroll;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure service that splits a worked time span into Costa/Honduras recargo bands.
 */
class BandSplitter
{
    /**
     * Backward-compatible static band fallback used when split is called without bands.
     */
    private const FALLBACK_BANDS = [
        ['start' => 0, 'end' => 360, 'bucket' => 'extra75'],
        ['start' => 360, 'end' => 840, 'bucket' => 'ordinary'],
        ['start' => 840, 'end' => 1080, 'bucket' => 'extra25'],
        ['start' => 1080, 'end' => 1440, 'bucket' => 'extra50'],
    ];

    /**
     * Splits a worked interval using fixed boundaries unless a custom band list is provided.
     *
     * Band format per item: ['start' => int, 'end' => int, 'bucket' => string]
     * Percentiles supported: ordinary, extra25, extra50, extra75, extra100.
     *
     * @param  array<int, array{start:int,end:int,bucket:string}>  $bands
     */
    public function split(CarbonInterface $entry, CarbonInterface $exit, array $bands = []): BandSplit
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
            'extra100' => 0.0,
        ];

        $bands = $bands === [] ? self::FALLBACK_BANDS : $bands;

        $day = $entry->startOfDay();
        $lastDay = $exit->startOfDay();

        while ($day->lte($lastDay)) {
            $dayStart = $day;
            $dayEnd = $day->copy()->addDay();

            $segmentStart = $entry->max($dayStart);
            $segmentEnd = $exit->min($dayEnd);

            if ($segmentStart < $segmentEnd) {
                foreach ($bands as $band) {
                    if (! isset($band['start'], $band['end'], $band['bucket'])) {
                        continue;
                    }

                    $startMinutes = (int) $band['start'];
                    $endMinutes = (int) $band['end'];
                    $bucket = (string) $band['bucket'];

                    $bandStart = $day->copy()->addMinutes($startMinutes);
                    $bandEnd = $day->copy()->addMinutes($endMinutes);

                    if ($bandEnd <= $bandStart) {
                        $bandEnd = $bandEnd->addDay();
                    }

                    if (! isset($totals[$bucket])) {
                        continue;
                    }

                    $overlapStart = $segmentStart->max($bandStart);
                    $overlapEnd = $segmentEnd->min($bandEnd);

                    if ($overlapStart < $overlapEnd) {
                        $totals[$bucket] += $overlapStart->floatDiffInMinutes($overlapEnd);
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
            extra100Minutes: $totals['extra100'],
        );
    }
}
