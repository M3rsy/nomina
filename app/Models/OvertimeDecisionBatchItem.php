<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvertimeDecisionBatchItem extends Model
{
    public const STATUSES = ['pending', 'processing', 'succeeded', 'failed'];

    protected $fillable = [
        'employee_id', 'work_date', 'candidate_key', 'fingerprint', 'status', 'attempts', 'last_error',
    ];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'attempts' => 'integer'];
    }
}
