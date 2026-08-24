<?php

namespace App\Livewire\Nomina;

use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\PayrollRunTelemetry;
use App\Services\Payroll\PayrollRunRequester;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Ramsey\Uuid\Uuid;

final class PayrollRunProgress extends Component
{
    public PayPeriod $payPeriod;

    #[Locked]
    public ?int $runId = null;

    public ?string $status = null;

    public bool $delayed = false;

    public ?string $failureCode = null;

    #[Locked]
    public bool $terminalNotified = false;

    public function mount(PayPeriod $payPeriod, int $runId): void
    {
        $this->authorize('view', $payPeriod);
        Gate::authorize('payroll.process');
        $this->payPeriod = $payPeriod;
        $this->runId = $runId;
        $this->poll();
    }

    public function poll(): void
    {
        $this->authorize('view', $this->payPeriod);
        Gate::authorize('payroll.process');

        $run = $this->runId === null ? null : PayrollRun::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->where('pay_period_id', $this->payPeriod->id)
            ->whereIn('status', [...PayrollRun::ACTIVE_STATUSES, PayrollRun::COMPLETED, PayrollRun::FAILED])
            ->find($this->runId);

        if ($run === null) {
            $this->runId = null;
            $this->status = null;
            $this->delayed = false;
            $this->failureCode = null;

            return;
        }

        $this->status = $run->status;
        $this->delayed = $run->status === PayrollRun::QUEUED
            && $run->created_at->lte(now()->subSeconds(15));
        $this->failureCode = $run->status === PayrollRun::FAILED
            ? PayrollRunTelemetry::query()->where('payroll_run_id', $run->id)->where('event', PayrollRunTelemetry::FAILED)
                ->latest('id')->value('code')
            : null;
        if (! $run->isActive() && ! $this->terminalNotified) {
            $this->terminalNotified = true;
            $this->dispatch('payroll-run-terminal', runId: $run->id);
        }
    }

    public function retry(): void
    {
        $this->authorize('view', $this->payPeriod);
        Gate::authorize('payroll.process');

        $failedRunId = $this->runId;
        $failedRun = $failedRunId === null ? null : PayrollRun::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->where('pay_period_id', $this->payPeriod->id)
            ->where('status', PayrollRun::FAILED)
            ->find($failedRunId);

        if ($failedRun === null) {
            $this->poll();

            return;
        }

        $run = app(PayrollRunRequester::class)->request(
            $this->payPeriod->fresh(),
            Auth::user(),
            $this->retryRequestKey($failedRun),
            $failedRun,
        );

        $this->runId = $run->id;
        $this->status = $run->status;
        $this->delayed = false;
        $this->failureCode = null;
        $this->terminalNotified = false;
        $this->dispatch('payroll-run-retried', failedRunId: $failedRunId, runId: $run->id);
    }

    private function retryRequestKey(PayrollRun $failedRun): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            implode(':', [
                'nomina-payroll-run-retry',
                $failedRun->company_id,
                $failedRun->pay_period_id,
                $failedRun->id,
                $failedRun->request_key,
            ]),
        )->toString();
    }

    public function render()
    {
        return view('livewire.nomina.payroll-run-progress');
    }
}
