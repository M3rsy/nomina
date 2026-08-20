<?php

namespace App\Services\Attendance;

use App\Services\Payroll\BandSplit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class AttendanceDecisionIdentity
{
    /**
     * @param  array{ordinary:int,extra25:int,extra50:int,extra75:int,extra100:int}  $rateMinutes
     */
    private function __construct(
        public string $key,
        public string $fingerprint,
        public string $segmentKind,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public int $minutes,
        public array $rateMinutes,
    ) {}

    public static function forSegment(
        string $kind,
        ?CarbonImmutable $start,
        ?CarbonImmutable $end,
        int $minutes,
        BandSplit $rates,
        string $fingerprint,
    ): self {
        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new InvalidArgumentException('Attendance decision fingerprints must be lowercase SHA256 values.');
        }

        $keyParts = $start === null
            ? [$kind, 'non-interval', $minutes, $fingerprint]
            : [$kind, $start->toIso8601String(), $end?->toIso8601String(), $fingerprint];

        return new self(
            key: hash('sha256', implode('|', $keyParts)),
            fingerprint: $fingerprint,
            segmentKind: $kind,
            startsAt: $start,
            endsAt: $end,
            minutes: $minutes,
            rateMinutes: [
                'ordinary' => $rates->ordinaryMinutes,
                'extra25' => $rates->extra25Minutes,
                'extra50' => $rates->extra50Minutes,
                'extra75' => $rates->extra75Minutes,
                'extra100' => $rates->extra100Minutes,
            ],
        );
    }

    public function matchesRecord(object $record, string $keyColumn): bool
    {
        return $record->{$keyColumn} === $this->key
            && $record->fingerprint === $this->fingerprint
            && $record->segment_kind === $this->segmentKind
            && $record->minutes === $this->minutes
            && $this->sameBoundary($record->starts_at, $this->startsAt)
            && $this->sameBoundary($record->ends_at, $this->endsAt)
            && $record->rate_minutes === $this->rateMinutes;
    }

    private function sameBoundary(?CarbonInterface $stored, ?CarbonInterface $expected): bool
    {
        return ($stored === null && $expected === null)
            || ($stored !== null && $expected !== null && $stored->equalTo($expected));
    }
}
