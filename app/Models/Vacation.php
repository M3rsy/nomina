<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vacation extends Model
{
    use BelongsToCompany, HasFactory;

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'employee_id', 'start_date', 'end_date', 'status', 'notes', 'excluded_dates',
        'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'excluded_dates' => 'array',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(VacationDay::class)->orderBy('work_date');
    }

    public function balanceMovements(): HasMany
    {
        return $this->hasMany(VacationBalanceMovement::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
