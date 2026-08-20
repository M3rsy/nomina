<?php

namespace App\Livewire\Nomina;

use App\Models\PayPeriod;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class PayrollRunProgress extends Component
{
    public PayPeriod $payPeriod;

    #[Locked]
    public ?int $runId = null;

    public ?string $status = null;

    public bool $delayed = false;

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

            return;
        }

        $this->status = $run->status;
        $this->delayed = $run->status === PayrollRun::QUEUED
            && $run->created_at->lte(now()->subSeconds(15));
        if (! $run->isActive() && ! $this->terminalNotified) {
            $this->terminalNotified = true;
            $this->dispatch('payroll-run-terminal', runId: $run->id);
        }
    }

    public function render()
    {
        return view('livewire.nomina.payroll-run-progress');
    }
}
