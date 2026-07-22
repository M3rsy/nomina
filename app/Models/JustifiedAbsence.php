<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\JustifiedAbsenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JustifiedAbsence extends Model
{
    /** @use HasFactory<JustifiedAbsenceFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'pay_period_id',
        'employee_id',
        'date',
        'reason',
        'notes',
        'justified_by',
        'schedule_fingerprint',
        'scheduled_start',
        'scheduled_end',
        'scheduled_minutes',
        'rate_minutes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'scheduled_start' => 'immutable_datetime',
            'scheduled_end' => 'immutable_datetime',
            'scheduled_minutes' => 'integer',
            'rate_minutes' => 'array',
            'metadata' => 'array',
        ];
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

    public function justifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by');
    }
}
