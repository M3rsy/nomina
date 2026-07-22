<?php

namespace App\Livewire\Dashboard;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
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
            'payrollTrends' => $companyId === null ? null : $this->monthlyPayrollTrends($companyId),
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

    private function monthlyPayrollTrends(int $companyId): array
    {
        $minuteColumns = [
            'ordinary_minutes' => 'ordinary_hours',
            'extra_25_minutes' => 'extra_25_hours',
            'extra_50_minutes' => 'extra_50_hours',
            'extra_75_minutes' => 'extra_75_hours',
            'extra_100_minutes' => 'extra_100_hours',
        ];

        $selects = ['date', 'count(*) as entries'];
        foreach ($minuteColumns as $minuteColumn => $hourColumn) {
            $selects[] = $this->minuteAggregateExpression($minuteColumn, $hourColumn);
        }

        $dailyTotals = PayrollResult::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->when($this->from, fn ($q) => $q->whereDate('date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('date', '<=', $this->to))
            ->selectRaw(implode(', ', $selects))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $months = [];

        foreach ($dailyTotals as $totals) {
            $month = $totals->date->format('Y-m');
            $months[$month] ??= [
                'month' => $month,
                'label' => ucfirst($totals->date->locale('es')->translatedFormat('F \\d\\e Y')),
                'entries' => 0,
                'ordinary_minutes' => 0,
                'extra_minutes' => 0,
            ];
            $months[$month]['entries'] += (int) $totals->entries;
            $months[$month]['ordinary_minutes'] += (int) $totals->ordinary_minutes;
            $months[$month]['extra_minutes'] += (int) $totals->extra_25_minutes
                + (int) $totals->extra_50_minutes
                + (int) $totals->extra_75_minutes
                + (int) $totals->extra_100_minutes;
        }

        $maxEntries = max(array_column($months, 'entries') ?: [1]);
        foreach ($months as &$monthTotals) {
            $monthTotals['ordinary_hours'] = $monthTotals['ordinary_minutes'] / 60;
            $monthTotals['extra_hours'] = $monthTotals['extra_minutes'] / 60;
            $monthTotals['bar_width'] = round($monthTotals['entries'] / $maxEntries * 100, 2);

            unset($monthTotals['ordinary_minutes'], $monthTotals['extra_minutes']);
        }
        unset($monthTotals);

        return array_values($months);
    }

    private function minuteAggregateExpression(string $minuteColumn, string $hourColumn): string
    {
        if (Schema::hasColumn('payroll_results', $minuteColumn)) {
            return "coalesce(sum({$minuteColumn}), 0) as {$minuteColumn}";
        }

        if (Schema::hasColumn('payroll_results', $hourColumn)) {
            return "coalesce(sum(round({$hourColumn} * 60)), 0) as {$minuteColumn}";
        }

        return "0 as {$minuteColumn}";
    }
}
