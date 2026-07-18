<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayPeriod extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

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

    public function isActive(): bool
    {
        return ! $this->trashed() && in_array($this->status, ['draft', 'uploaded', 'ready'], true);
    }

    public function canUploadFiles(): bool
    {
        return ! $this->trashed() && in_array($this->status, ['draft', 'uploaded', 'validation_failed', 'ready'], true);
    }
}
