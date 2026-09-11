<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VacationBalanceMovement extends Model
{
    use BelongsToCompany, HasFactory;

    public const UPDATED_AT = null;

    public const MANUAL_ADJUSTMENT = 'manual_adjustment';

    public const VACATION_CONSUMPTION = 'vacation_consumption';

    public const VACATION_REVERSAL = 'vacation_reversal';

    protected $fillable = [
        'company_id', 'employee_id', 'vacation_id', 'vacation_day_id', 'type', 'days', 'reason',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return ['days' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Vacation balance movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Vacation balance movements are append-only.'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function vacation(): BelongsTo
    {
        return $this->belongsTo(Vacation::class);
    }

    public function vacationDay(): BelongsTo
    {
        return $this->belongsTo(VacationDay::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
