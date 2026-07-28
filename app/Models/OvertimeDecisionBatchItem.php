<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OvertimeDecisionBatchItem extends Model
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const STATUSES = ['pending', 'processing', 'succeeded', 'failed'];

    protected $fillable = [
        'employee_id', 'work_date', 'candidate_key', 'fingerprint', 'status', 'attempts', 'last_error',
    ];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'attempts' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OvertimeDecisionBatch::class, 'batch_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(OvertimeDecision::class, 'batch_item_id');
    }
}
