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
     * Each complete elapsed minute is classified by the instant at which it starts.
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
            'ordinary' => 0,
            'extra25' => 0,
            'extra50' => 0,
            'extra75' => 0,
            'extra100' => 0,
        ];

        $bands = $bands === [] ? self::FALLBACK_BANDS : $bands;

        $wholeMinutes = (int) floor($entry->diffInSeconds($exit) / 60);

        for ($offset = 0; $offset < $wholeMinutes; $offset++) {
            $instant = $entry->addMinutes($offset);
            $bucket = $this->bucketAt($instant, $bands);

            if ($bucket !== null) {
                $totals[$bucket]++;
            }
        }

        return new BandSplit(
            ordinaryMinutes: $totals['ordinary'],
            extra25Minutes: $totals['extra25'],
            extra50Minutes: $totals['extra50'],
            extra75Minutes: $totals['extra75'],
            extra100Minutes: $totals['extra100'],
        );
    }

    /** @param array<int, array{start:int,end:int,bucket:string}> $bands */
    private function bucketAt(CarbonImmutable $instant, array $bands): ?string
    {
        $minuteOfDay = $instant->hour * 60 + $instant->minute;

        foreach ($bands as $band) {
            if (! isset($band['start'], $band['end'], $band['bucket'])) {
                continue;
            }

            $start = (int) $band['start'];
            $end = (int) $band['end'];
            $bucket = (string) $band['bucket'];
            $end = $end <= $start ? $end + 1440 : $end;
            $matches = $end <= 1440
                ? $minuteOfDay >= $start && $minuteOfDay < $end
                : $minuteOfDay >= $start || $minuteOfDay < $end - 1440;

            if ($matches && in_array($bucket, ['ordinary', 'extra25', 'extra50', 'extra75', 'extra100'], true)) {
                return $bucket;
            }
        }

        return null;
    }
}
