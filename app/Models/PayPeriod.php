<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayPeriod extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    public const UPLOADABLE_STATUSES = [
        'draft',
        'uploaded',
        'validation_failed',
    ];

    protected $fillable = [
        'company_id',
        'slug',
        'name',
        'start_date',
        'end_date',
        'status',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'metadata' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    protected function endDate(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => $value === null
                ? null
                : $this->asDateTime($value)->endOfDay(),
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploadedFiles(): HasMany
    {
        return $this->hasMany(UploadedFile::class);
    }

    public function rawMarks(): HasMany
    {
        return $this->hasMany(RawMark::class);
    }

    public function justifiedAbsences(): HasMany
    {
        return $this->hasMany(JustifiedAbsence::class);
    }

    public function payrollResults(): HasMany
    {
        return $this->hasMany(PayrollResult::class);
    }

    public function overtimeDecisions(): HasMany
    {
        return $this->hasMany(OvertimeDecision::class);
    }

    public function isActive(): bool
    {
        return ! $this->trashed() && in_array($this->status, ['draft', 'uploaded', 'ready'], true);
    }

    public function canUploadFiles(): bool
    {
        return ! $this->trashed() && in_array($this->status, self::UPLOADABLE_STATUSES, true);
    }

    public function scopeUploadable(Builder $query): Builder
    {
        return $query->whereIn('status', self::UPLOADABLE_STATUSES);
    }
}
