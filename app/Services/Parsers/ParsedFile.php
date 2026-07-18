<?php

namespace App\Services\Parsers;

use Illuminate\Support\Collection;

class ParsedFile
{
    /**
     * @param  Collection<int, RawMarkPayload>  $records
     */
    public function __construct(
        public Collection $records,
        public ?string $encoding = null,
        public int $totalLines = 0,
    ) {}
}
