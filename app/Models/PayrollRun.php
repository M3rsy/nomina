<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PayrollRun extends Model
{
    use BelongsToCompany;

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const ACTIVE_STATUSES = [self::QUEUED, self::PROCESSING];

    protected $fillable = [
        'request_key',
        'company_id',
        'pay_period_id',
        'requested_by',
        'status',
        'started_at',
        'finished_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'active_key' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function markProcessing(): void
    {
        $this->transition([self::QUEUED], self::PROCESSING, [
            'started_at' => $this->started_at ?? now(),
            'last_error' => null,
        ]);
    }

    public function markCompleted(): void
    {
        $this->finish([self::PROCESSING], self::COMPLETED);
    }

    public function markFailed(string $error): void
    {
        $this->finish(self::ACTIVE_STATUSES, self::FAILED, $error);
    }

    private function finish(array $from, string $status, ?string $error = null): void
    {
        $this->transition($from, $status, [
            'active_key' => null,
            'finished_at' => now(),
            'last_error' => $error,
        ]);
    }

    private function transition(array $from, string $status, array $attributes): void
    {
        $updated = self::withoutCompanyScope()
            ->whereKey($this->getKey())
            ->whereIn('status', $from)
            ->where('active_key', true)
            ->update(['status' => $status, ...$attributes]);

        if ($updated !== 1) {
            throw new LogicException("Payroll run cannot transition to {$status}.");
        }

        $this->refresh();
    }
}
