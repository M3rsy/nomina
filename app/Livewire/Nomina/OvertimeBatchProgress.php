<?php

namespace App\Livewire\Nomina;

use App\Models\OvertimeDecisionBatch;
use App\Models\PayPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OvertimeBatchProgress extends Component
{
    public PayPeriod $payPeriod;

    #[Locked]
    public ?int $batchId = null;

    public array $progress = [];

    public array $batchErrors = [];

    #[Locked]
    public bool $terminalNotified = false;

    public function mount(PayPeriod $payPeriod, int $batchId): void
    {
        $this->authorize('view', $payPeriod);
        Gate::authorize('marks.manage');
        $this->payPeriod = $payPeriod;
        $this->batchId = $batchId;
        $this->poll();
    }

    public function poll(): void
    {
        if ($this->batchId === null) {
            return;
        }

        $batch = OvertimeDecisionBatch::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->where('pay_period_id', $this->payPeriod->id)
            ->where('requested_by', Auth::id())
            ->find($this->batchId);
        if ($batch === null) {
            $unavailableBatchId = $this->batchId;
            $this->batchId = null;
            $this->progress = [];
            $this->batchErrors = [];
            $this->dispatch('overtime-batch-unavailable', batchId: $unavailableBatchId);

            return;
        }

        $counts = $batch->items()->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');
        $this->progress = [
            'status' => $batch->status,
            'total' => $batch->total_items,
            'pending' => (int) ($counts['pending'] ?? 0),
            'processing' => (int) ($counts['processing'] ?? 0),
            'succeeded' => (int) ($counts['succeeded'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'terminal' => in_array($batch->status, [
                OvertimeDecisionBatch::COMPLETED,
                OvertimeDecisionBatch::COMPLETED_WITH_ERRORS,
                'failed',
            ], true),
        ];
        $this->batchErrors = $batch->items()->where('status', 'failed')
            ->whereNotNull('last_error')->limit(5)->pluck('last_error')->all();
        if ($this->progress['terminal'] && ! $this->terminalNotified) {
            $this->terminalNotified = true;
            $this->dispatch('overtime-batch-terminal', batchId: $batch->id);
        }
    }

    public function render()
    {
        return view('livewire.nomina.overtime-batch-progress');
    }
}
