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
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
