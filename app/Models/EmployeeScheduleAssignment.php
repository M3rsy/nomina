<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeScheduleAssignment extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'employee_id',
        'work_schedule_profile_id',
        'effective_from',
        'effective_to',
        'assigned_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleProfile::class, 'work_schedule_profile_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
