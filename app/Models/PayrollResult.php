<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollResult extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollResultFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'pay_period_id',
        'employee_id',
        'date',
        'entry_at',
        'exit_at',
        'worked_hours',
        'ordinary_hours',
        'extra_25_hours',
        'extra_50_hours',
        'extra_75_hours',
        'extra_100_hours',
        'is_absence',
        'is_justified',
        'unjustified',
        'notes',
        'rules_version',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'entry_at' => 'datetime',
            'exit_at' => 'datetime',
            'worked_hours' => 'decimal:2',
            'ordinary_hours' => 'decimal:2',
            'extra_25_hours' => 'integer',
            'extra_50_hours' => 'integer',
            'extra_75_hours' => 'integer',
            'extra_100_hours' => 'integer',
            'is_absence' => 'boolean',
            'is_justified' => 'boolean',
            'unjustified' => 'boolean',
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
