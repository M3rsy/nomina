<?php

use App\Services\Payroll\PayrollRunMetrics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

test('isolates query measurements between payroll run scopes', function (): void {
    $metrics = app(PayrollRunMetrics::class);
    $query = new QueryExecuted('select * from employees where dni = ?', ['sensitive-value'], 7.4, DB::connection());

    $metrics->record($query);
    $metrics->begin();
    $metrics->record($query);
    $first = $metrics->finish();
    $metrics->record($query);
    $metrics->begin();
    $metrics->record($query);
    $second = $metrics->finish();
    $metrics->begin();
    $wait = $metrics->finish(now()->subSecond());

    expect($first)->toMatchArray([
        'query_count' => 1,
        'db_time_ms' => 7,
    ])->and($second)->toMatchArray([
        'query_count' => 1,
        'db_time_ms' => 7,
    ])->and($wait['queue_wait_ms'])->toBeGreaterThanOrEqual(1000);
});
