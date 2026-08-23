<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLogEntry extends Model
{
    use HasFactory;

    protected $table = 'audit_entries';

    protected $fillable = [
        'company_id',
        'type',
        'occurred_at',
        'actor_id',
        'user_identifier',
        'description',
        'metadata',
        'subject_type',
        'subject_id',
        'source_type',
        'source_id',
        'source_revision',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withDefault();
    }
}
