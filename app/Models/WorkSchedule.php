<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSchedule extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'work_schedule_profile_id',
        'day_of_week',
        'is_working_day',
        'base_ordinary_hours',
        'start_time',
        'end_time',
        'banding_json',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_working_day' => 'boolean',
            'base_ordinary_hours' => 'decimal:2',
            'banding_json' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleProfile::class, 'work_schedule_profile_id');
    }
}
