<?php

namespace App\Services;

class ValidationReport
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $issues
     * @param  array<int, string>  $rowStatuses
     */
    public function __construct(
        public array $counts = [],
        public array $issues = [],
        public array $rowStatuses = [],
    ) {}
}
