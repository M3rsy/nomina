<?php

namespace App\Models;

use App\Services\DatabaseSessionRevoker;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'legal_id',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $company): void {
            if (empty($company->slug)) {
                $company->slug = Str::slug($company->name);
            }
        });

        static::saving(function (self $company): void {
            if (empty($company->slug)) {
                $company->slug = Str::slug($company->name);
            }
        });

        static::updating(function (self $company): void {
            if (! ($company->isDirty('is_active') && $company->getOriginal('is_active') && ! $company->is_active)) {
                return;
            }

            $companyId = $company->id;

            DB::afterCommit(function () use ($companyId): void {
                $revokedSessions = app(DatabaseSessionRevoker::class)->revokeCompanyUsers($companyId);

                if ($revokedSessions > 0) {
                    Log::warning('Company access revoked', [
                        'event' => 'company_access_revoked',
                        'company_id' => $companyId,
                        'revoked_sessions' => $revokedSessions,
                    ]);
                }
            });
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    public function workScheduleProfiles(): HasMany
    {
        return $this->hasMany(WorkScheduleProfile::class);
    }

    public function overtimeDecisions(): HasMany
    {
        return $this->hasMany(OvertimeDecision::class);
    }

    public function attendanceExceptions(): HasMany
    {
        return $this->hasMany(AttendanceException::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public static function defaultWorkSchedules(): array
    {
        return [
            ['day_of_week' => 1, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 2, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 3, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 4, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 5, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 6, 'is_working_day' => true, 'base_ordinary_hours' => 4.00, 'start_time' => '08:00', 'end_time' => '12:00', 'notes' => null],
            ['day_of_week' => 0, 'is_working_day' => false, 'base_ordinary_hours' => 0.00, 'start_time' => null, 'end_time' => null, 'notes' => null],
        ];
    }
}
