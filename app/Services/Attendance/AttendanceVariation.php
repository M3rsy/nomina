<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

readonly class AttendanceVariation
{
    public string $key;

    public function __construct(
        public string $kind,
        public CarbonImmutable $entryAt,
        public string $fingerprint,
    ) {
        $this->key = hash('sha256', implode('|', [$kind, $entryAt->toIso8601String(), $fingerprint]));
    }
}
