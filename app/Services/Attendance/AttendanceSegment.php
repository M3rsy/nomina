<?php

namespace App\Services\Attendance;

use App\Services\Payroll\BandSplit;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

readonly class AttendanceSegment
{
    public int $minutes;

    public string $key;

    public string $fingerprint;

    public AttendanceDecisionIdentity $identity;

    /** @var list<AttendanceDecisionIdentity> */
    private array $compatibleIdentities;

    public function __construct(
        public string $kind,
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        string $fingerprint,
        public BandSplit $rateMinutes,
        ?int $minutes = null,
        array $compatibleIdentities = [],
    ) {
        if ($start === null && $end === null) {
            if ($minutes === null || $minutes < 1 || $rateMinutes->totalMinutes() !== $minutes) {
                throw new InvalidArgumentException('A non-interval attendance fact must contain whole minutes.');
            }

            $this->minutes = $minutes;
        } elseif ($start === null || $end === null) {
            throw new InvalidArgumentException('An attendance interval requires both boundaries.');
        } else {
            $seconds = $start->diffInSeconds($end);

            if ($end->lte($start) || $seconds < 60) {
                throw new InvalidArgumentException('Attendance segment must contain at least one whole minute.');
            }

            $this->minutes = (int) floor($seconds / 60);
        }

        $this->identity = AttendanceDecisionIdentity::forSegment(
            $kind, $start, $end, $this->minutes, $rateMinutes, $fingerprint,
        );
        $this->key = $this->identity->key;
        $this->fingerprint = $this->identity->fingerprint;
        foreach ($compatibleIdentities as $compatibleIdentity) {
            if (! $compatibleIdentity instanceof AttendanceDecisionIdentity) {
                throw new InvalidArgumentException('Compatible attendance identities must preserve their key and fingerprint pair.');
            }
        }
        $this->compatibleIdentities = collect($compatibleIdentities)
            ->reject(fn (AttendanceDecisionIdentity $compatible): bool => $compatible->key === $this->key)
            ->unique(fn (AttendanceDecisionIdentity $compatible): string => $compatible->key.'|'.$compatible->fingerprint)
            ->values()
            ->all();
    }

    /** @return list<AttendanceDecisionIdentity> */
    public function identities(): array
    {
        return [$this->identity, ...$this->compatibleIdentities];
    }

    public function withCompatibleFingerprint(string $fingerprint): self
    {
        return $this->withCompatibleIdentities([
            AttendanceDecisionIdentity::forSegment(
                $this->kind,
                $this->start,
                $this->end,
                $this->minutes,
                $this->rateMinutes,
                $fingerprint,
            ),
        ]);
    }

    /** @param list<AttendanceDecisionIdentity> $identities */
    public function withCompatibleIdentities(array $identities): self
    {
        return new self(
            $this->kind,
            $this->start,
            $this->end,
            $this->fingerprint,
            $this->rateMinutes,
            $this->minutes,
            [...$this->compatibleIdentities, ...$identities],
        );
    }
}
