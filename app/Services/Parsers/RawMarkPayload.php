<?php

namespace App\Services\Parsers;

use Carbon\Carbon;

class RawMarkPayload
{
    public function __construct(
        public string $employee_external_id,
        public Carbon $event_at,
        public string $raw_line,
        public int $row_number,
        public string $source,
        public array $metadata = [],
    ) {}
}
