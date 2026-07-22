<?php

namespace App\Livewire\Nomina;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
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

    public bool $showApproveConfirm = false;

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
        $results = $this->queryResults();
        $summary = $this->summary();
        $employees = Employee::where('company_id', $this->payPeriod->company_id)
            ->orderBy('first_name')
            ->get();

        return view('livewire.nomina.procesar', [
            'results' => $results,
            'summary' => $summary,
            'employees' => $employees,
            'isCancelled' => $this->isCancelled(),
            'canApprove' => $this->canApprove(),
            'canExport' => $this->canExport(),
        ]);
    }

    public function updatingEmployeeId(): void
    {
        $this->resetPage();
    }

    public function openApproveConfirm(): void
    {
        if ($this->payPeriod->status !== 'processed') {
            return;
        }

        $this->showApproveConfirm = true;
    }

    public function closeApproveConfirm(): void
    {
        $this->showApproveConfirm = false;
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
        $this->showApproveConfirm = false;

        if (! $approved) {
            return;
        }

        session()->flash('success', 'Nómina aprobada correctamente.');
        $this->dispatch('close-approve-modal');
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

    private function queryResults()
    {
        return PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $this->payPeriod->id)
            ->with('employee')
            ->when($this->employee_id, function ($query) {
                $query->where('employee_id', $this->employee_id);
            })
            ->orderBy('employee_id')
            ->orderBy('date')
            ->paginate(50);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $query = PayrollResult::withoutCompanyScope()
            ->where('pay_period_id', $this->payPeriod->id)
            ->when($this->employee_id, function ($query) {
                $query->where('employee_id', $this->employee_id);
            });

        $totals = (clone $query)->selectRaw(
            'count(*) as total_records, count(distinct employee_id) as total_employees, sum(ordinary_minutes) as ordinary_minutes, sum(extra_25_minutes) as extra_25_minutes, sum(extra_50_minutes) as extra_50_minutes, sum(extra_75_minutes) as extra_75_minutes, sum(extra_100_minutes) as extra_100_minutes'
        )->first();

        return [
            'total_employees' => (int) ($totals?->total_employees ?? 0),
            'total_records' => (int) ($totals?->total_records ?? 0),
            'ordinary_hours' => (int) ($totals?->ordinary_minutes ?? 0) / 60,
            'extra_25_hours' => (int) ($totals?->extra_25_minutes ?? 0) / 60,
            'extra_50_hours' => (int) ($totals?->extra_50_minutes ?? 0) / 60,
            'extra_75_hours' => (int) ($totals?->extra_75_minutes ?? 0) / 60,
            'extra_100_hours' => (int) ($totals?->extra_100_minutes ?? 0) / 60,
        ];
    }
}
