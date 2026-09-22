<?php

namespace App\Livewire\Vacaciones;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Vacation;
use App\Services\Vacations\VacationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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

    public string $vacationEmployeeSearch = '';

    public string $adjustmentEmployeeSearch = '';

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

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->resetVacationForm();
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
            Auth::user(),
        );

        $this->showCreateModal = false;
        $this->resetVacationForm();
        $this->dispatch('vacation-saved');
    }

    public function openAdjustmentModal(): void
    {
        $this->authorize('create', Vacation::class);
        $this->ensureCompanySelected();
        $this->resetAdjustmentForm();
        $this->showAdjustmentModal = true;
    }

    public function closeAdjustmentModal(): void
    {
        $this->showAdjustmentModal = false;
        $this->resetAdjustmentForm();
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
            Auth::user(),
        );
        $this->showAdjustmentModal = false;
        $this->resetAdjustmentForm();
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
        $manager->cancel($vacation, $validated['cancellationReason'], Auth::user());
        $this->showCancelModal = false;
        $this->cancellingId = null;
        $this->dispatch('vacation-cancelled');
    }

    public function render()
    {
        $companyId = current_company_id();
        $vacationEmployees = $this->employeePickerEmployees($companyId, $this->vacationEmployeeSearch, true);
        $adjustmentEmployees = $this->employeePickerEmployees($companyId, $this->adjustmentEmployeeSearch);
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

        return view('livewire.vacaciones.index', compact(
            'vacations', 'vacationEmployees', 'adjustmentEmployees', 'balances', 'companyId'
        ));
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
        $this->vacationEmployeeSearch = '';
        $this->startDate = '';
        $this->endDate = '';
        $this->notes = '';
        $this->resetErrorBag();
    }

    private function resetAdjustmentForm(): void
    {
        $this->employeeId = null;
        $this->adjustmentEmployeeSearch = '';
        $this->adjustmentDays = '';
        $this->adjustmentReason = '';
        $this->resetErrorBag();
    }

    private function employeePickerEmployees(?int $companyId, string $search, bool $activeOnly = false): Collection
    {
        if ($companyId === null) {
            return collect();
        }

        $term = trim($search);

        return Employee::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.$term.'%';
                $query->where(function (Builder $employee) use ($like): void {
                    $employee->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('external_id', 'like', $like)
                        ->orWhere('payment_code', 'like', $like);
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

}
