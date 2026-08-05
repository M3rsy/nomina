<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleProfilePublication extends Model
{
    use BelongsToCompany;

    public const SCHEDULE_OVERLAP_V1 = 'schedule-overlap-v1';

    public const DURATION_FIRST_V2 = 'duration-first-v2';

    protected $fillable = [
        'company_id',
        'profile_key',
        'profile_id',
        'payroll_policy_key',
        'effective_from',
        'effective_to',
        'definition_hash',
        'request_key',
        'payload_hash',
        'reason',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    public static function createLegacyFor(WorkScheduleProfile $profile): self
    {
        $identity = implode('|', [$profile->company_id, $profile->profile_key, $profile->id]);

        return self::withoutCompanyScope()->create([
            'company_id' => $profile->company_id,
            'profile_key' => $profile->profile_key,
            'profile_id' => $profile->id,
            'payroll_policy_key' => self::SCHEDULE_OVERLAP_V1,
            'effective_from' => '1970-01-01',
            'effective_to' => null,
            'definition_hash' => hash('sha256', "legacy-definition|{$identity}"),
            'request_key' => hash('sha256', "legacy-request|{$identity}"),
            'payload_hash' => hash('sha256', "legacy-payload|{$identity}"),
            'reason' => 'Legacy payroll policy identity',
            'published_by' => null,
        ]);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleProfile::class, 'profile_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
