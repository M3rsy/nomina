<?php

namespace App\Services\Payroll;

use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;

final class PayrollRunMetrics
{
    private bool $active = false;

    private float $startedAt = 0.0;

    private int $queryCount = 0;

    private float $dbTimeMs = 0.0;

    public function begin(): void
    {
        $this->active = true;
        $this->startedAt = microtime(true);
        $this->queryCount = 0;
        $this->dbTimeMs = 0.0;

        memory_reset_peak_usage();
    }

    public function record(QueryExecuted $query): void
    {
        if (! $this->active) {
            return;
        }

        $this->queryCount++;
        $this->dbTimeMs += $query->time;
    }

    /** @return array<string, int> */
    public function finish(?CarbonInterface $queuedAt = null): array
    {
        $finishedAt = microtime(true);
        $this->active = false;

        return [
            'duration_ms' => max(0, (int) round(($finishedAt - $this->startedAt) * 1000)),
            'queue_wait_ms' => $queuedAt === null ? 0 : max(0, $queuedAt->diffInMilliseconds(now(), false)),
            'db_time_ms' => max(0, (int) round($this->dbTimeMs)),
            'query_count' => $this->queryCount,
            'peak_memory_mb' => max(0, (int) ceil(memory_get_peak_usage(true) / 1024 / 1024)),
        ];
    }
}
