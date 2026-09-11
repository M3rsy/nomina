<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacationDay extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'vacation_id', 'employee_id', 'work_date', 'scheduled_start', 'scheduled_end',
        'planned_minutes', 'rate_minutes', 'snapshot_fingerprint', 'holiday_generation',
        'employee_schedule_assignment_id', 'work_schedule_id', 'work_schedule_profile_publication_id',
        'active_marker',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'planned_minutes' => 'integer',
            'rate_minutes' => 'array',
            'holiday_generation' => 'integer',
            'active_marker' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_marker', true);
    }

    public function scopeForEmployeeDate(Builder $query, int $employeeId, mixed $workDate): Builder
    {
        return $query->where('employee_id', $employeeId)->whereDate('work_date', $workDate);
    }

    public function vacation(): BelongsTo
    {
        return $this->belongsTo(Vacation::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeScheduleAssignment::class, 'employee_schedule_assignment_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleProfilePublication::class, 'work_schedule_profile_publication_id');
    }
}
