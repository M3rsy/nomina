<?php

namespace App\Livewire\Nomina;

use App\Models\OvertimeDecisionBatch;
use App\Models\PayPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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

    public function mount(PayPeriod $payPeriod, ?int $batchId = null): void
    {
        $this->authorize('view', $payPeriod);
        Gate::authorize('marks.manage');
        $this->payPeriod = $payPeriod;
        $this->batchId = $batchId;
        $this->poll();
    }

    #[On('overtime-batch-started')]
    public function start(int $batchId): void
    {
        $this->batchId = $batchId;
        $this->progress = [];
        $this->batchErrors = [];
        $this->terminalNotified = false;
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

        $statusRows = $batch->items()
            ->selectRaw('status, count(*) as total, max(updated_at) as latest_activity_at')
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        $pending = (int) ($statusRows->get('pending')?->total ?? 0);
        $processing = (int) ($statusRows->get('processing')?->total ?? 0);
        $succeeded = (int) ($statusRows->get('succeeded')?->total ?? 0);
        $failed = (int) ($statusRows->get('failed')?->total ?? 0);
        $total = (int) $batch->total_items;
        $completed = $succeeded + $failed;
        $terminal = in_array($batch->status, [
            OvertimeDecisionBatch::COMPLETED,
            OvertimeDecisionBatch::COMPLETED_WITH_ERRORS,
            'failed',
        ], true);
        $latestActivity = collect([
            $batch->created_at,
            $batch->updated_at,
            $batch->started_at,
            $batch->finished_at,
            ...$statusRows->pluck('latest_activity_at')->filter()->map(
                fn (string $timestamp): Carbon => Carbon::parse($timestamp),
            ),
        ])->filter()->sortByDesc(fn (CarbonInterface $timestamp): int => $timestamp->getTimestamp())->first();
        $delayed = ! $terminal
            && $latestActivity instanceof CarbonInterface
            && $latestActivity->lt(now()->subSeconds(30));

        $this->progress = [
            'status' => $batch->status,
            'total' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'completed' => $completed,
            'remaining' => max(0, $total - $completed),
            'percentage' => $total > 0 ? min(100, max(0, (int) round(($completed / $total) * 100))) : null,
            'terminal' => $terminal,
            'delayed' => $delayed,
            'delay_reason' => $delayed ? match ($batch->status) {
                OvertimeDecisionBatch::QUEUED => 'queued_without_recent_activity',
                OvertimeDecisionBatch::PROCESSING => 'processing_without_recent_activity',
                default => 'nonterminal_without_recent_activity',
            } : null,
            'created_at' => $batch->created_at?->toIso8601String(),
            'started_at' => $batch->started_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
            'latest_activity_at' => $latestActivity?->toIso8601String(),
            'last_error' => $batch->last_error,
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
