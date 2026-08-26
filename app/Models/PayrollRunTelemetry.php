<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PayrollRunTelemetry extends Model
{
    public const QUEUED = 'queued';

    public const STARTED = 'started';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $table = 'payroll_run_telemetry';

    protected $fillable = [
        'payroll_run_id',
        'previous_run_id',
        'event',
        'code',
        'occurred_at',
        'duration_ms',
        'queue_wait_ms',
        'db_time_ms',
        'query_count',
        'peak_memory_mb',
        'employee_count',
        'day_count',
        'result_count',
        'inserted_count',
        'reused_count',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'queue_wait_ms' => 'integer',
            'db_time_ms' => 'integer',
            'query_count' => 'integer',
            'peak_memory_mb' => 'integer',
            'employee_count' => 'integer',
            'day_count' => 'integer',
            'result_count' => 'integer',
            'inserted_count' => 'integer',
            'reused_count' => 'integer',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
