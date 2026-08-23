<?php

namespace App\Models;

use App\Services\Auditoria\AuditEntryProjector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'email',
        'ip',
        'user_agent',
        'success',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (self $attempt) => app(AuditEntryProjector::class)->project($attempt));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
