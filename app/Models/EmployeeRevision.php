<?php

namespace App\Models;

use App\Services\Auditoria\AuditEntryProjector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'user_id',
        'field',
        'old_value',
        'new_value',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (self $revision) => app(AuditEntryProjector::class)->project($revision));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault();
    }
}
