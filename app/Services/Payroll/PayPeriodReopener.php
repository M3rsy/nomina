<?php

namespace App\Services\Payroll;

use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PayPeriodReopener
{
    public function reopen(PayPeriod $payPeriod, string $reason, User $actor): PayPeriod
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Debe indicar el motivo de la reapertura.']);
        }

        return DB::transaction(function () use ($payPeriod, $reason, $actor): PayPeriod {
            $lockedPeriod = PayPeriod::withoutCompanyScope()
                ->lockForUpdate()
                ->findOrFail($payPeriod->id);

            Gate::forUser($actor)->authorize('manage', $lockedPeriod);

            if (! $actor->is_active || $lockedPeriod->status !== 'processed') {
                throw ValidationException::withMessages([
                    'payPeriod' => 'Solo una nómina procesada puede reabrirse.',
                ]);
            }

            $results = PayrollResult::withoutCompanyScope()
                ->where('company_id', $lockedPeriod->company_id)
                ->where('pay_period_id', $lockedPeriod->id);
            $invalidatedResults = $results->count();
            $results->delete();

            $metadata = $lockedPeriod->metadata ?? [];
            $reopenings = $metadata['reopenings'] ?? [];
            $reopenings[] = [
                'from_status' => 'processed',
                'to_status' => 'validating',
                'reason' => $reason,
                'user_id' => $actor->id,
                'invalidated_results' => $invalidatedResults,
                'at' => now()->toDateTimeString(),
            ];
            $metadata['reopenings'] = $reopenings;

            $lockedPeriod->update([
                'status' => 'validating',
                'metadata' => $metadata,
            ]);

            return $lockedPeriod->refresh();
        });
    }
}
