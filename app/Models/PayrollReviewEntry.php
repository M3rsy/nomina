<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollReviewEntry extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'pay_period_id',
        'employee_id',
        'work_date',
        'type',
        'status',
        'source_key',
        'source_fingerprint',
        'generation',
        'occurred_at',
        'rate_ordinary_minutes',
        'rate_extra25_minutes',
        'rate_extra50_minutes',
        'rate_extra75_minutes',
        'rate_extra100_minutes',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'occurred_at' => 'immutable_datetime',
            'rate_ordinary_minutes' => 'integer',
            'rate_extra25_minutes' => 'integer',
            'rate_extra50_minutes' => 'integer',
            'rate_extra75_minutes' => 'integer',
            'rate_extra100_minutes' => 'integer',
            'payload' => 'array',
        ];
    }

    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
