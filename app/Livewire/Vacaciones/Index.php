<?php

namespace App\Livewire\Vacaciones;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Vacation;
use App\Services\Vacations\VacationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    public bool $showCreateModal = false;

    public bool $showAdjustmentModal = false;

    public bool $showCancelModal = false;

    public ?int $employeeId = null;

    public string $startDate = '';

    public string $endDate = '';

    public string $notes = '';

    public int|string $adjustmentDays = '';

    public string $adjustmentReason = '';

    public ?int $cancellingId = null;

    public string $cancellationReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Vacation::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->authorize('create', Vacation::class);
        $this->ensureCompanySelected();
        $this->resetVacationForm();
        $this->showCreateModal = true;
    }

    public function approve(VacationManager $manager): void
    {
        $this->authorize('create', Vacation::class);
        $companyId = $this->ensureCompanySelected();
        $validated = $this->validate([
            'employeeId' => [
                'required', 'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')),
            ],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $manager->approve(
            Company::query()->findOrFail($companyId),
            Employee::withoutCompanyScope()->findOrFail((int) $validated['employeeId']),
            $validated['startDate'],
            $validated['endDate'],
            $validated['notes'],
            auth()->user(),
        );
        $this->showCreateModal = false;
        $this->resetVacationForm();
        $this->dispatch('vacation-saved');
    }

    public function openAdjustmentModal(): void
    {
        $this->authorize('create', Vacation::class);
        $this->ensureCompanySelected();
        $this->employeeId = null;
        $this->adjustmentDays = '';
        $this->adjustmentReason = '';
        $this->resetErrorBag();
        $this->showAdjustmentModal = true;
    }

    public function adjustBalance(VacationManager $manager): void
    {
        $this->authorize('create', Vacation::class);
        $companyId = $this->ensureCompanySelected();
        $validated = $this->validate([
            'employeeId' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'adjustmentDays' => ['required', 'integer', 'not_in:0', 'between:-999,999'],
            'adjustmentReason' => ['required', 'string', 'max:2000'],
        ]);
        $manager->adjustBalance(
            Company::query()->findOrFail($companyId),
            Employee::withoutCompanyScope()->findOrFail((int) $validated['employeeId']),
            (int) $validated['adjustmentDays'],
            $validated['adjustmentReason'],
            auth()->user(),
        );
        $this->showAdjustmentModal = false;
        $this->dispatch('vacation-balance-adjusted');
    }

    public function confirmCancellation(int $vacationId): void
    {
        $vacation = Vacation::query()->findOrFail($vacationId);
        $this->authorize('cancel', $vacation);
        $this->cancellingId = $vacationId;
        $this->cancellationReason = '';
        $this->resetErrorBag();
        $this->showCancelModal = true;
    }

    public function cancel(VacationManager $manager): void
    {
        $validated = $this->validate(['cancellationReason' => ['required', 'string', 'max:2000']]);
        $vacation = Vacation::query()->findOrFail($this->cancellingId);
        $this->authorize('cancel', $vacation);
        $manager->cancel($vacation, $validated['cancellationReason'], auth()->user());
        $this->showCancelModal = false;
        $this->cancellingId = null;
        $this->dispatch('vacation-cancelled');
    }

    public function render()
    {
        $companyId = current_company_id();
        $employees = Employee::query()->orderBy('first_name')->orderBy('last_name')->get();
        $vacations = Vacation::query()
            ->with(['employee', 'days'])
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%'.trim($this->search).'%';
                $query->whereHas('employee', fn (Builder $employee) => $employee
                    ->where(function (Builder $names) use ($term): void {
                        $names->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('external_id', 'like', $term);
                    }));
            })
            ->latest('start_date')
            ->paginate(15);
        $balances = $companyId === null
            ? collect()
            : Employee::withoutCompanyScope()
                ->where('company_id', $companyId)
                ->withSum('vacationBalanceMovements as vacation_balance', 'days')
                ->get()->pluck('vacation_balance', 'id');

        return view('livewire.vacaciones.index', compact('vacations', 'employees', 'balances', 'companyId'));
    }

    private function ensureCompanySelected(): int
    {
        $companyId = current_company_id();
        abort_if($companyId === null, 422, 'Seleccioná una empresa para gestionar vacaciones.');

        return $companyId;
    }

    private function resetVacationForm(): void
    {
        $this->employeeId = null;
        $this->startDate = '';
        $this->endDate = '';
        $this->notes = '';
        $this->resetErrorBag();
    }
}
