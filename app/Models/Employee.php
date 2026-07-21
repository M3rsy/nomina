<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Employee extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'external_id',
        'first_name',
        'last_name',
        'dni',
        'sex',
        'birth_date',
        'address',
        'phone',
        'job_title',
        'expected_salary',
        'is_active',
        'hired_at',
        'notes',
        'metadata',
    ];

    /**
     * @var list<string>
     */
    protected const SENSITIVE_FIELDS = [
        'dni',
        'expected_salary',
        'job_title',
        'external_id',
        'hired_at',
        'is_active',
    ];

    protected static bool $withoutRevisions = false;

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hired_at' => 'date',
            'expected_salary' => 'decimal:2',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $employee): void {
            if (static::$withoutRevisions) {
                return;
            }

            $revisions = [];

            foreach (self::SENSITIVE_FIELDS as $field) {
                if (! $employee->isDirty($field)) {
                    continue;
                }

                $old = $employee->getOriginal($field);
                $new = $employee->getAttribute($field);

                if ($old === $new) {
                    continue;
                }

                $revisions[] = [
                    'field' => $field,
                    'old_value' => $old !== null ? (string) $old : null,
                    'new_value' => $new !== null ? (string) $new : null,
                ];
            }

            if ($revisions === []) {
                return;
            }

            $userId = Auth::check() ? Auth::id() : null;

            foreach ($revisions as $revision) {
                $employee->revisions()->create([
                    'user_id' => $userId,
                    'field' => $revision['field'],
                    'old_value' => $revision['old_value'],
                    'new_value' => $revision['new_value'],
                    'reason' => null,
                ]);
            }
        });
    }

    public static function withoutRevisions(callable $callback): void
    {
        static::$withoutRevisions = true;

        try {
            $callback();
        } finally {
            static::$withoutRevisions = false;
        }
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EmployeeRevision::class);
    }

    public function justifiedAbsences(): HasMany
    {
        return $this->hasMany(JustifiedAbsence::class);
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    public function overtimeDecisions(): HasMany
    {
        return $this->hasMany(OvertimeDecision::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
