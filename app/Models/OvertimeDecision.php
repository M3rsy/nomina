<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class OvertimeDecision extends Model
{
    use BelongsToCompany, HasFactory;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PARTIAL = 'partial';

    public const WHOLE_APPROVE = 'whole_approve';

    public const WHOLE_REJECT = 'whole_reject';

    public const UPDATED_AT = null;

    protected $fillable = [
        'record_version',
        'company_id',
        'pay_period_id',
        'employee_id',
        'work_date',
        'candidate_key',
        'fingerprint',
        'segment_kind',
        'starts_at',
        'ends_at',
        'minutes',
        'rate_minutes',
        'decision',
        'reason',
        'decided_by',
        'supersedes_id',
        'batch_item_id',
        'resolution_kind',
        'approved_starts_at',
        'approved_ends_at',
        'rejected_before_starts_at',
        'rejected_before_ends_at',
        'rejected_after_starts_at',
        'rejected_after_ends_at',
        'approved_minutes',
        'rejected_minutes',
        'rejected_before_minutes',
        'rejected_after_minutes',
        'approved_rate_minutes',
        'rejected_rate_minutes',
        'resolution_hash',
    ];

    protected function casts(): array
    {
        return [
            'record_version' => 'integer',
            'work_date' => 'date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'minutes' => 'integer',
            'rate_minutes' => 'array',
            'approved_starts_at' => 'immutable_datetime',
            'approved_ends_at' => 'immutable_datetime',
            'rejected_before_starts_at' => 'immutable_datetime',
            'rejected_before_ends_at' => 'immutable_datetime',
            'rejected_after_starts_at' => 'immutable_datetime',
            'rejected_after_ends_at' => 'immutable_datetime',
            'approved_minutes' => 'integer',
            'rejected_minutes' => 'integer',
            'rejected_before_minutes' => 'integer',
            'rejected_after_minutes' => 'integer',
            'approved_rate_minutes' => 'array',
            'rejected_rate_minutes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Overtime decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Overtime decisions are append-only.'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withDefault();
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersedingDecision(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(OvertimeDecisionBatchItem::class, 'batch_item_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'supersedingDecision',
            fn (Builder $superseding): Builder => $superseding->withoutGlobalScopes(),
        );
    }
}
