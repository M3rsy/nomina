<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PayrollResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollResult extends Model
{
    /** @use HasFactory<PayrollResultFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'pay_period_id',
        'employee_id',
        'employee_external_id',
        'employee_name',
        'date',
        'entry_at',
        'exit_at',
        'worked_hours',
        'ordinary_hours',
        'extra_25_hours',
        'extra_50_hours',
        'extra_75_hours',
        'extra_100_hours',
        'worked_minutes',
        'scheduled_minutes',
        'recognized_minutes',
        'detected_overtime_minutes',
        'approved_overtime_minutes',
        'ordinary_minutes',
        'extra_25_minutes',
        'extra_50_minutes',
        'extra_75_minutes',
        'extra_100_minutes',
        'is_absence',
        'is_justified',
        'unjustified',
        'notes',
        'rules_version',
        'calendar_generation',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'entry_at' => 'datetime',
            'exit_at' => 'datetime',
            'employee_external_id' => 'string',
            'employee_name' => 'string',
            'worked_hours' => 'decimal:2',
            'ordinary_hours' => 'decimal:2',
            'extra_25_hours' => 'decimal:2',
            'extra_50_hours' => 'decimal:2',
            'extra_75_hours' => 'decimal:2',
            'extra_100_hours' => 'decimal:2',
            'worked_minutes' => 'integer',
            'scheduled_minutes' => 'integer',
            'recognized_minutes' => 'integer',
            'detected_overtime_minutes' => 'integer',
            'approved_overtime_minutes' => 'integer',
            'ordinary_minutes' => 'integer',
            'extra_25_minutes' => 'integer',
            'extra_50_minutes' => 'integer',
            'extra_75_minutes' => 'integer',
            'extra_100_minutes' => 'integer',
            'is_absence' => 'boolean',
            'is_justified' => 'boolean',
            'unjustified' => 'boolean',
            'calendar_generation' => 'integer',
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
}
