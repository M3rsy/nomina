<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OvertimeDecisionBatch extends Model
{
    use BelongsToCompany;

    public const QUEUED = 'queued';

    public const STATUSES = ['queued', 'processing', 'completed', 'completed_with_errors', 'failed'];

    protected $fillable = [
        'request_key', 'payload_hash', 'company_id', 'pay_period_id', 'requested_by',
        'decision', 'reason', 'status', 'total_items', 'started_at', 'finished_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['total_items' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OvertimeDecisionBatchItem::class, 'batch_id');
    }
}
