<?php

namespace App\Livewire\Nomina;

use App\Models\PayPeriod;
use App\Services\Payroll\OvertimeReviewReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OvertimeReviewPanel extends Component
{
    use WithPagination;

    public PayPeriod $payPeriod;

    #[Locked]
    public ?int $uploadedFileId = null;

    #[Url]
    public string $overtimeStatus = 'pending';

    #[Url]
    public string $overtimeSearch = '';

    #[Url]
    public string $overtimeDate = '';

    #[Url]
    public string $overtimeRate = '';

    public array $selectedOvertimeCandidates = [];

    public bool $allFilteredOvertimeSelected = false;

    public function mount(PayPeriod $payPeriod, ?int $uploadedFileId = null): void
    {
        $this->authorize('view', $payPeriod);
        Gate::authorize('marks.manage');
        $this->payPeriod = $payPeriod;
        $this->uploadedFileId = $uploadedFileId;
        $this->normalizeFilters();
    }

    public function updatingOvertimeSearch(): void
    {
        $this->resetPanelState();
    }

    public function updatingOvertimeStatus(): void
    {
        $this->resetPanelState();
    }

    public function updatingOvertimeDate(): void
    {
        $this->resetPanelState();
    }

    public function updatingOvertimeRate(): void
    {
        $this->resetPanelState();
    }

    public function selectCurrentOvertimePage(): void
    {
        $this->allFilteredOvertimeSelected = false;
        $this->selectedOvertimeCandidates = $this->pendingTargets($this->getPage('overtimePage'))->keys()->all();
    }

    public function selectAllFilteredOvertime(): void
    {
        $this->allFilteredOvertimeSelected = true;
        $this->selectedOvertimeCandidates = [];
    }

    public function clearOvertimeSelection(): void
    {
        $this->selectedOvertimeCandidates = [];
        $this->allFilteredOvertimeSelected = false;
    }

    public function render()
    {
        $data = app(OvertimeReviewReader::class)->forPeriod(
            $this->payPeriod, $this->uploadedFileId, $this->filters(), $this->getPage('overtimePage'),
        );

        return view('livewire.nomina.overtime-review-panel', [
            'overtimeGroups' => $data['groups'],
            'overtimeRows' => $data['rows'],
            'pendingOvertimeMatchCount' => $data['pendingCount'],
        ]);
    }

    private function resetPanelState(): void
    {
        $this->resetPage('overtimePage');
        $this->clearOvertimeSelection();
    }

    private function pendingTargets(?int $page = null): Collection
    {
        return app(OvertimeReviewReader::class)->pendingTargetsForPeriod(
            $this->payPeriod, $this->uploadedFileId, $this->filters(), $page,
        );
    }

    private function filters(): array
    {
        return [
            'search' => mb_strtolower(trim($this->overtimeSearch)),
            'status' => $this->overtimeStatus,
            'date' => $this->overtimeDate,
            'rate' => $this->overtimeRate,
        ];
    }

    private function normalizeFilters(): void
    {
        if (! in_array($this->overtimeStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
            $this->overtimeStatus = 'pending';
        }
        if (! in_array($this->overtimeRate, ['', 'ordinary', 'extra25', 'extra50', 'extra75', 'extra100'], true)) {
            $this->overtimeRate = '';
        }
        if ($this->overtimeDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->overtimeDate)) {
            $this->overtimeDate = '';
        }
    }
}
