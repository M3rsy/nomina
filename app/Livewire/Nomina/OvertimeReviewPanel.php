<?php

namespace App\Livewire\Nomina;

use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Services\Payroll\OvertimeReviewReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OvertimeReviewPanel extends Component
{
    use WithPagination;

    public PayPeriod $payPeriod;

    #[Locked]
    public ?int $uploadedFileId = null;

    #[Reactive]
    public bool $isBlocked = false;

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

    public bool $showOvertimeDecisionModal = false;

    public ?int $overtimeDecisionEmployeeId = null;

    public string $overtimeDecisionWorkDate = '';

    public string $overtimeCandidateKey = '';

    public string $overtimeDecision = '';

    public string $overtimeDecisionReason = '';

    public string $overtimeCandidateSummary = '';

    public string $overtimeApprovedStartsAt = '';

    public string $overtimeApprovedEndsAt = '';

    public bool $showOvertimeBatchModal = false;

    public string $overtimeBatchDecision = '';

    public string $overtimeBatchReason = '';

    public string $overtimeBatchRequestKey = '';

    public string $overtimeBatchSelection = '';

    public int $overtimeBatchCount = 0;

    public string $overtimeBatchFilterSummary = '';

    public function mount(PayPeriod $payPeriod, ?int $uploadedFileId = null, bool $isBlocked = false): void
    {
        $this->authorize('view', $payPeriod);
        Gate::authorize('marks.manage');
        $this->payPeriod = $payPeriod;
        $this->uploadedFileId = $uploadedFileId;
        $this->isBlocked = $isBlocked;
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

    public function openOvertimeDecision(int $employeeId, string $workDate, string $candidateKey, string $decision, string $summary, string $startsAt, string $endsAt): void
    {
        if (! in_array($decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED, OvertimeDecision::PARTIAL], true)) {
            return;
        }

        $this->overtimeDecisionEmployeeId = $employeeId;
        $this->overtimeDecisionWorkDate = $workDate;
        $this->overtimeCandidateKey = $candidateKey;
        $this->overtimeDecision = $decision;
        $this->overtimeCandidateSummary = $summary;
        $this->overtimeApprovedStartsAt = $startsAt;
        $this->overtimeApprovedEndsAt = $endsAt;
        $this->showOvertimeDecisionModal = true;
    }

    public function closeOvertimeDecisionModal(): void
    {
        $this->reset([
            'showOvertimeDecisionModal', 'overtimeDecisionEmployeeId', 'overtimeDecisionWorkDate',
            'overtimeCandidateKey', 'overtimeDecision', 'overtimeDecisionReason', 'overtimeCandidateSummary',
            'overtimeApprovedStartsAt', 'overtimeApprovedEndsAt',
        ]);
        $this->resetErrorBag();
    }

    public function openOvertimeBatch(string $decision): void
    {
        if ($this->isBlocked || ! in_array($decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)) {
            return;
        }

        $targets = $this->resolvedOvertimeBatchTargets();
        if ($targets->count() > 500) {
            $this->addError('selectedOvertimeCandidates', 'Hay más de 500 candidatos pendientes. Aplique filtros más específicos antes de continuar.');

            return;
        }
        if ($targets->isEmpty()) {
            $this->addError('selectedOvertimeCandidates', 'Seleccione al menos un candidato pendiente.');

            return;
        }

        $this->resetErrorBag();
        $this->overtimeBatchDecision = $decision;
        $this->overtimeBatchSelection = $this->overtimeBatchConfirmation($targets);
        $this->overtimeBatchCount = $targets->count();
        $this->overtimeBatchFilterSummary = $this->overtimeFilterSummary();
        $this->overtimeBatchReason = '';
        $this->overtimeBatchRequestKey = (string) Str::uuid();
        $this->showOvertimeBatchModal = true;
    }

    public function closeOvertimeBatchModal(): void
    {
        $this->reset([
            'showOvertimeBatchModal', 'overtimeBatchDecision', 'overtimeBatchReason', 'overtimeBatchRequestKey',
            'overtimeBatchSelection', 'overtimeBatchCount', 'overtimeBatchFilterSummary',
        ]);
        $this->resetErrorBag();
    }

    public function submitOvertimeBatch(): void
    {
        if ($this->isBlocked || ! $this->showOvertimeBatchModal) {
            return;
        }

        $data = $this->validate([
            'overtimeBatchDecision' => ['required', Rule::in([OvertimeDecision::APPROVED, OvertimeDecision::REJECTED])],
            'overtimeBatchReason' => ['required', 'string', 'max:500'],
            'overtimeBatchRequestKey' => ['required', 'uuid'],
        ], ['overtimeBatchReason.required' => 'Debe indicar un motivo común.']);
        if ($this->overtimeBatchConfirmation($this->resolvedOvertimeBatchTargets()) !== $this->overtimeBatchSelection) {
            $this->addError('selectedOvertimeCandidates', 'La selección cambió. Revísela antes de continuar.');

            return;
        }

        $this->dispatch('overtime-batch-submitted', intent: [
            'decision' => $data['overtimeBatchDecision'],
            'reason' => $data['overtimeBatchReason'],
            'request_key' => $data['overtimeBatchRequestKey'],
            'selection' => $this->overtimeBatchSelection,
            'filters' => $this->filters(),
            'all' => $this->allFilteredOvertimeSelected,
            'selected' => $this->selectedOvertimeCandidates,
        ]);
    }

    public function submitOvertimeDecision(): void
    {
        $data = $this->validate([
            'overtimeDecisionEmployeeId' => ['required', 'integer'],
            'overtimeDecisionWorkDate' => ['required', 'date_format:Y-m-d'],
            'overtimeCandidateKey' => ['required', 'string', 'size:64'],
            'overtimeDecision' => ['required', 'in:approved,rejected,partial'],
            'overtimeDecisionReason' => ['required', 'string', 'max:500'],
            'overtimeApprovedStartsAt' => ['required_if:overtimeDecision,partial', 'date_format:Y-m-d\TH:i'],
            'overtimeApprovedEndsAt' => ['required_if:overtimeDecision,partial', 'date_format:Y-m-d\TH:i', 'after:overtimeApprovedStartsAt'],
        ]);
        $this->dispatch('overtime-decision-submitted', decision: $data);
    }

    #[On('overtime-decision-recorded')]
    public function refreshAfterOvertimeDecision(): void
    {
        $this->closeOvertimeDecisionModal();
        $this->resetPanelState();
    }

    #[On('overtime-batch-recorded')]
    public function refreshAfterOvertimeBatch(): void
    {
        $this->closeOvertimeBatchModal();
        $this->resetPanelState();
    }

    #[On('overtime-batch-rejected')]
    public function rejectOvertimeBatch(string $message): void
    {
        $this->addError('selectedOvertimeCandidates', $message);
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
        $this->closeOvertimeBatchModal();
    }

    private function pendingTargets(?int $page = null): Collection
    {
        return app(OvertimeReviewReader::class)->pendingTargetsForPeriod(
            $this->payPeriod, $this->uploadedFileId, $this->filters(), $page,
        );
    }

    private function resolvedOvertimeBatchTargets(): Collection
    {
        if ($this->allFilteredOvertimeSelected) {
            return $this->pendingTargets();
        }

        $selected = collect($this->selectedOvertimeCandidates)
            ->filter(fn (mixed $token): bool => is_string($token)
                && preg_match('/^\d+\|\d{4}-\d{2}-\d{2}\|[a-f0-9]{64}$/D', $token) === 1)
            ->flip()->all();

        return $this->pendingTargets()
            ->filter(fn (array $target, string $token): bool => isset($selected[$token]));
    }

    private function overtimeBatchConfirmation(Collection $targets): string
    {
        return hash('sha256', json_encode([
            'filters' => $this->filters(),
            'all' => $this->allFilteredOvertimeSelected,
            'candidates' => $targets->map(fn (array $target, string $token): string => $token.'|'.$target['fingerprint'])
                ->sort()->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function overtimeFilterSummary(): string
    {
        $parts = ['Estado: Pendientes'];
        if (($search = trim($this->overtimeSearch)) !== '') {
            $parts[] = 'Empleado: '.$search;
        }
        if ($this->overtimeDate !== '') {
            $parts[] = 'Fecha: '.$this->overtimeDate;
        }
        if ($this->overtimeRate !== '') {
            $parts[] = 'Porcentaje: '.match ($this->overtimeRate) {
                'ordinary' => 'Ordinario', 'extra25' => '25%', 'extra50' => '50%',
                'extra75' => '75%', 'extra100' => '100%', default => $this->overtimeRate,
            };
        }
        if ($this->uploadedFileId !== null) {
            $file = $this->payPeriod->uploadedFiles()->whereKey($this->uploadedFileId)->value('original_name');
            if ($file !== null) {
                $parts[] = 'Archivo: '.$file;
            }
        }

        return implode(' · ', $parts);
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
