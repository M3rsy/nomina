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

    public array $payrollOverview = [];

    public function mount(): void
    {
        $this->authorize('companies.view');
    }

    public function render()
    {
        $company = current_company();
        $companyId = $company?->id;
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
        $this->payrollOverview = $company === null
            ? []
            : $this->buildPayrollOverview((int) $company->id, $company->name);

        return view('livewire.dashboard.super-admin', [
            'activeCompanies' => $this->activeCompanies,
            'inactiveCompanies' => $this->inactiveCompanies,
            'activeUsers' => $this->activeUsers,
            'activeEmployees' => $this->activeEmployees,
            'payrollOverview' => $this->payrollOverview,
            'generalStats' => $this->generalStats($companyIds),
        ]);
    }

    private function buildPayrollOverview(int $companyId, string $companyName): array
    {
        $counts = PayPeriod::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->when($this->from, fn ($q) => $q->whereDate('start_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('end_date', '<=', $this->to))
            ->selectRaw('status, count(*) as status_count')
            ->groupBy('status')
            ->pluck('status_count', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $preparation = ($counts['draft'] ?? 0) + ($counts['uploaded'] ?? 0)
            + ($counts['validating'] ?? 0) + ($counts['ready'] ?? 0);
        $processing = $counts['processing'] ?? 0;
        $completed = ($counts['processed'] ?? 0) + ($counts['approved'] ?? 0) + ($counts['exported'] ?? 0);
        $validationFailed = $counts['validation_failed'] ?? 0;
        $cancelled = $counts['cancelled'] ?? 0;
        $total = array_sum($counts);
        $hasPeriods = $total > 0;

        if (! $hasPeriods && ($this->from || $this->to)) {
            $hasPeriods = PayPeriod::withoutCompanyScope()
                ->where('company_id', $companyId)
                ->exists();
        }

        return [
            'company_name' => $companyName,
            'total' => $total,
            'preparation' => $preparation,
            'processing' => $processing,
            'completed' => $completed,
            'validation_failed' => $validationFailed,
            'cancelled' => $cancelled,
            'unknown' => $total - $preparation - $processing - $completed - $validationFailed - $cancelled,
            'has_periods' => $hasPeriods,
        ];
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
