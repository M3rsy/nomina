<?php

namespace App\Livewire\Dashboard;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class SuperAdmin extends Component
{
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public int $activeCompanies = 0;

    public int $inactiveCompanies = 0;

    public int $activeUsers = 0;

    public int $activeEmployees = 0;

    public int $processedPayrolls = 0;

    public int $pendingPayrolls = 0;

    public int $errorPayrolls = 0;

    public function mount(): void
    {
        $this->authorize('companies.view');
    }

    public function render()
    {
        $companyId = current_company_id();
        $companyIds = $companyId !== null ? [$companyId] : null;

        $this->activeCompanies = Company::query()
            ->when($companyId !== null, fn ($query) => $query->whereKey($companyId))
            ->where('is_active', true)
            ->count();
        $this->inactiveCompanies = Company::query()
            ->when($companyId !== null, fn ($query) => $query->whereKey($companyId))
            ->where('is_active', false)
            ->count();
        $this->activeUsers = User::query()
            ->where('is_active', true)
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->count();
        $this->activeEmployees = Employee::withoutCompanyScope()
            ->where('is_active', true)
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->count();
        $this->processedPayrolls = $this->payPeriodQuery($companyIds)
            ->whereIn('status', ['processed', 'approved', 'exported'])
            ->count();
        $this->pendingPayrolls = $this->payPeriodQuery($companyIds)
            ->whereIn('status', ['draft', 'uploaded', 'validating', 'ready'])
            ->count();
        $this->errorPayrolls = $this->payPeriodQuery($companyIds)
            ->where('status', 'validation_failed')
            ->count();

        return view('livewire.dashboard.super-admin', [
            'activeCompanies' => $this->activeCompanies,
            'inactiveCompanies' => $this->inactiveCompanies,
            'activeUsers' => $this->activeUsers,
            'activeEmployees' => $this->activeEmployees,
            'processedPayrolls' => $this->processedPayrolls,
            'pendingPayrolls' => $this->pendingPayrolls,
            'errorPayrolls' => $this->errorPayrolls,
            'generalStats' => $this->generalStats($companyIds),
        ]);
    }

    private function payPeriodQuery(?array $companyIds)
    {
        return PayPeriod::withoutCompanyScope()
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->when($this->from, fn ($q) => $q->whereDate('start_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('end_date', '<=', $this->to));
    }

    private function generalStats(?array $companyIds): array
    {
        $results = PayrollResult::withoutCompanyScope()
            ->with('company')
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->when($this->from, fn ($q) => $q->whereDate('date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('date', '<=', $this->to))
            ->limit(1000)
            ->get();

        return $results
            ->groupBy(fn (PayrollResult $result) => $result->date->format('Y-m'))
            ->map(fn ($group, $month) => [
                'month' => $month,
                'company_name' => $group->first()->company?->name ?? 'Todas',
                'entries' => $group->count(),
                'ordinary_hours' => $group->sum('ordinary_hours'),
                'extra_hours' => $group->sum(fn (PayrollResult $r) => $r->extra_25_hours + $r->extra_50_hours + $r->extra_75_hours + $r->extra_100_hours),
            ])
            ->sortKeysDesc()
            ->values()
            ->toArray();
    }
}
