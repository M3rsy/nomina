<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeImportBatch extends Model
{
    use BelongsToCompany, HasFactory;

    public const FAILED = 'failed';

    public const COMPLETED = 'completed';

    protected $fillable = [
        'company_id',
        'actor_id',
        'original_filename',
        'status',
        'total_rows',
        'read_rows',
        'imported_rows',
        'error_summary',
        'error_details',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'read_rows' => 'integer',
            'imported_rows' => 'integer',
            'error_summary' => 'array',
            'error_details' => 'array',
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
