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
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'occurred_at' => 'immutable_datetime',
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
