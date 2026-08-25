<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class AbandonedPayrollRunRecovery
{
    public function __construct(private PayrollRunTelemetryRecorder $telemetry) {}

    public function recover(PayrollRun $candidate, User $actor): bool
    {
        return DB::transaction(function () use ($candidate, $actor): bool {
            $company = Company::query()->whereKey($candidate->company_id)->lockForUpdate()->firstOrFail();
            $period = PayPeriod::withoutCompanyScope()->where('company_id', $company->id)
                ->lockForUpdate()->findOrFail($candidate->pay_period_id);
            $run = PayrollRun::withoutCompanyScope()->where('company_id', $company->id)
                ->where('pay_period_id', $period->id)->lockForUpdate()->findOrFail($candidate->id);

            $this->authorize($actor, $period);
            if (! $run->isActive() || ! $run->lease_expires_at?->isPast()) {
                return false;
            }

            $code = $run->status === PayrollRun::QUEUED ? 'queued_abandoned' : 'worker_abandoned';
            $run->markFailed('Payroll run lease expired before completion.');
            $this->telemetry->failedWithCode($run, $code);

            return true;
        });
    }

    private function authorize(User $actor, PayPeriod $period): void
    {
        if (! $actor->is_active || ! $actor->can('payroll.process')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $period->company_id)) {
            throw new AuthorizationException('Not authorized to recover this pay period.');
        }
    }
}
