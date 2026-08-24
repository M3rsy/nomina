<?php

namespace App\Livewire\Nomina;

use App\Models\PayPeriod;
use App\Services\Payroll\PayrollResultsReviewProjection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Procesar extends Component
{
    use WithPagination;

    public PayPeriod $payPeriod;

    #[Url]
    public ?int $employee_id = null;

    #[Url]
    public ?string $absence = null;

    public ?int $evidenceResultId = null;

    public bool $locked = false;

    public function mount(PayPeriod $payPeriod): void
    {
        $this->authorize('view', $payPeriod);
        Gate::authorize('payroll.process');

        if (! in_array($payPeriod->status, ['processed', 'approved', 'exported'], true)) {
            session()->flash('warning', 'Primero debe procesar las marcas.');

            $this->redirectRoute('nomina.revisar', ['payPeriod' => $payPeriod]);

            return;
        }

        $this->payPeriod = $payPeriod;
        $this->locked = in_array($payPeriod->status, ['approved', 'exported', 'cancelled'], true);
    }

    public function render()
    {
        $projection = app(PayrollResultsReviewProjection::class);

        return view('livewire.nomina.procesar', [
            'results' => $projection->page($this->payPeriod, $this->employee_id, $this->absence),
            'summary' => $projection->summary($this->payPeriod, $this->employee_id, $this->absence),
            'evidence' => $projection->evidence($this->payPeriod, $this->evidenceResultId),
            'isCancelled' => $this->isCancelled(),
            'canApprove' => $this->canApprove(),
            'canExport' => $this->canExport(),
        ]);
    }

    public function updatingEmployeeId(): void
    {
        $this->resetPage();
    }

    public function updatingAbsence(): void
    {
        $this->resetPage();
    }

    public function showEvidence(int $resultId): void
    {
        $this->evidenceResultId = $resultId;
    }

    public function approve(): void
    {
        Gate::authorize('payroll.approve');

        [$approved, $freshPeriod] = DB::transaction(function (): array {
            $lockedPeriod = PayPeriod::withoutCompanyScope()
                ->lockForUpdate()
                ->findOrFail($this->payPeriod->id);

            if ($lockedPeriod->status !== 'processed') {
                return [false, $lockedPeriod];
            }

            $metadata = $lockedPeriod->metadata ?? [];
            $metadata['approved_at'] = now()->toDateTimeString();
            $metadata['approved_by'] = Auth::id();

            $lockedPeriod->update([
                'status' => 'approved',
                'metadata' => $metadata,
            ]);

            return [true, $lockedPeriod->refresh()];
        });

        $this->payPeriod = $freshPeriod;
        $this->locked = in_array($freshPeriod->status, ['approved', 'exported', 'cancelled'], true);
        if (! $approved) {
            return;
        }

        session()->flash('success', 'Nómina aprobada correctamente.');
    }

    public function canExport(): bool
    {
        return Gate::allows('payroll.export')
            && in_array($this->payPeriod->status, ['approved', 'exported'], true);
    }

    public function canApprove(): bool
    {
        return Gate::allows('payroll.approve')
            && $this->payPeriod->status === 'processed';
    }

    public function isCancelled(): bool
    {
        return $this->payPeriod->status === 'cancelled';
    }
}
