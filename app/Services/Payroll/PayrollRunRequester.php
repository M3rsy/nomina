<?php

namespace App\Services\Payroll;

use App\Jobs\ProcessPayrollRun;
use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PayrollRunRequester
{
    public function request(PayPeriod $period, User $actor, string $requestKey): PayrollRun
    {
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'The request key must be a UUID.']);
        }

        try {
            $run = DB::transaction(function () use ($period, $actor, $requestKey): PayrollRun {
                $company = Company::query()->whereKey($period->company_id)->lockForUpdate()->firstOrFail();
                $period = PayPeriod::withoutCompanyScope()
                    ->withTrashed()
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->findOrFail($period->id);
                $actor = User::query()->findOrFail($actor->id);

                $this->authorize($actor, $period);

                if ($priorRun = $this->priorRun($requestKey, $period)) {
                    return $priorRun;
                }

                $activeRun = $this->activeRun($period);

                if ($activeRun !== null) {
                    return $activeRun;
                }

                if ($period->trashed() || $period->status !== 'ready') {
                    throw ValidationException::withMessages([
                        'pay_period' => 'Only a ready pay period can be processed.',
                    ]);
                }

                return PayrollRun::withoutCompanyScope()->create([
                    'request_key' => $requestKey,
                    'company_id' => $period->company_id,
                    'pay_period_id' => $period->id,
                    'requested_by' => $actor->id,
                    'status' => PayrollRun::QUEUED,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $run = $this->priorRun($requestKey, $period)
                ?? $this->activeRun($period)
                ?? throw $exception;
        }

        if ($run->status === PayrollRun::QUEUED) {
            DB::afterCommit(fn () => ProcessPayrollRun::dispatch($run->id));
        }

        return $run;
    }

    private function authorize(User $actor, PayPeriod $period): void
    {
        if (! $actor->is_active
            || ! $actor->can('payroll.process')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $period->company_id)) {
            throw new AuthorizationException('Not authorized to process this pay period.');
        }
    }

    private function activeRun(PayPeriod $period): ?PayrollRun
    {
        return PayrollRun::withoutCompanyScope()
            ->where('pay_period_id', $period->id)
            ->where('active_key', true)
            ->first();
    }

    private function priorRun(string $requestKey, PayPeriod $period): ?PayrollRun
    {
        $run = PayrollRun::withoutCompanyScope()->where('request_key', $requestKey)->first();

        if ($run !== null && $run->pay_period_id !== $period->id) {
            throw ValidationException::withMessages(['request_key' => 'The request key belongs to another pay period.']);
        }

        return $run;
    }
}
