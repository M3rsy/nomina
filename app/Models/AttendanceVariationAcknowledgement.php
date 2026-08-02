<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AttendanceVariationAcknowledgement extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'record_version', 'company_id', 'pay_period_id', 'employee_id', 'work_date',
        'variation_key', 'fingerprint', 'variation_kind', 'entry_at', 'reason', 'acknowledged_by',
    ];

    protected function casts(): array
    {
        return [
            'record_version' => 'integer',
            'work_date' => 'date',
            'entry_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Variation acknowledgements are append-only.'));
        static::deleting(fn () => throw new LogicException('Variation acknowledgements are append-only.'));
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

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by')->withDefault();
    }
}
