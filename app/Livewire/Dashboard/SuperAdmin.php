<?php

namespace App\Livewire\Dashboard;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRevision;
use App\Models\LoginAttempt;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\RawMark;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class SuperAdmin extends Component
{
    #[Url]
    public ?int $company_id = null;

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
        $selectedCompany = $this->company_id
            ? Company::query()->find($this->company_id)
            : null;

        $companyIds = $selectedCompany ? [$selectedCompany->id] : null;

        $this->activeCompanies = Company::query()->where('is_active', true)->count();
        $this->inactiveCompanies = Company::query()->where('is_active', false)->count();
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

        $companies = Company::query()->orderBy('name')->get();

        return view('livewire.dashboard.super-admin', [
            'activeCompanies' => $this->activeCompanies,
            'inactiveCompanies' => $this->inactiveCompanies,
            'activeUsers' => $this->activeUsers,
            'activeEmployees' => $this->activeEmployees,
            'processedPayrolls' => $this->processedPayrolls,
            'pendingPayrolls' => $this->pendingPayrolls,
            'errorPayrolls' => $this->errorPayrolls,
            'companies' => $companies,
            'selectedCompany' => $selectedCompany,
            'recentActivity' => $this->recentActivity($companyIds),
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

    private function recentActivity(?array $companyIds): array
    {
        $items = [];

        $loginAttempts = LoginAttempt::query()
            ->with('user', 'company')
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('created_at')
            ->limit(20)
            ->get();

        foreach ($loginAttempts as $attempt) {
            $items[] = $this->buildActivityItem(
                'login_attempt',
                'Inicio de sesión',
                $attempt->created_at,
                $attempt->company_id,
                $attempt->company?->name ?? 'Sistema',
                $attempt->user_id,
                $attempt->email,
                ($attempt->success ? 'Éxito' : 'Fallido').' desde IP '.$attempt->ip
            );
        }

        $employeeRevisions = EmployeeRevision::query()
            ->with(['user', 'employee.company'])
            ->when($companyIds, fn ($q) => $q->whereHas('employee', fn ($sq) => $sq->withoutGlobalScope('company')->whereIn('company_id', $companyIds)))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('created_at')
            ->limit(20)
            ->get();

        foreach ($employeeRevisions as $revision) {
            $items[] = $this->buildActivityItem(
                'employee_revision',
                'Empleado modificado',
                $revision->created_at,
                $revision->employee?->company_id,
                $revision->employee?->company?->name ?? 'Desconocida',
                $revision->user_id,
                $revision->user?->email,
                "Campo {$revision->field}: '{$revision->old_value}' → '{$revision->new_value}'"
            );
        }

        $payPeriods = PayPeriod::withoutCompanyScope()
            ->with('company')
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->whereNotNull('metadata')
            ->limit(100)
            ->get();

        foreach ($payPeriods as $payPeriod) {
            $metadata = $payPeriod->metadata ?? [];
            foreach (['approved', 'exported', 'processed'] as $action) {
                $at = $metadata[$action.'_at'] ?? null;
                if (! $at) {
                    continue;
                }

                $createdAt = Carbon::parse($at);
                if ($this->from && $createdAt->lt(Carbon::parse($this->from)->startOfDay())) {
                    continue;
                }
                if ($this->to && $createdAt->gt(Carbon::parse($this->to)->endOfDay())) {
                    continue;
                }

                $items[] = $this->buildActivityItem(
                    'payroll_state',
                    'Nómina '.ucfirst($action),
                    $createdAt,
                    $payPeriod->company_id,
                    $payPeriod->company?->name ?? 'Desconocida',
                    $metadata[$action.'_by'] ?? null,
                    null,
                    "Período {$payPeriod->name} pasó a estado {$action}"
                );
            }
        }

        $rawMarks = RawMark::withoutCompanyScope()
            ->with('company')
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->whereNotNull('metadata')
            ->limit(100)
            ->get();

        foreach ($rawMarks as $rawMark) {
            foreach ($rawMark->metadata['revisions'] ?? [] as $rev) {
                $at = $rev['at'] ?? null;
                if (! $at) {
                    continue;
                }

                $createdAt = Carbon::parse($at);
                if ($this->from && $createdAt->lt(Carbon::parse($this->from)->startOfDay())) {
                    continue;
                }
                if ($this->to && $createdAt->gt(Carbon::parse($this->to)->endOfDay())) {
                    continue;
                }

                $items[] = $this->buildActivityItem(
                    'mark_revision',
                    'Marca revisada',
                    $createdAt,
                    $rawMark->company_id,
                    $rawMark->company?->name ?? 'Desconocida',
                    $rev['user_id'] ?? null,
                    null,
                    "Acción {$rev['action']} en marca #{$rawMark->id} (empleado {$rawMark->employee_external_id})"
                );
            }
        }

        usort($items, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return array_slice($items, 0, 20);
    }

    private function buildActivityItem(
        string $type,
        string $typeLabel,
        Carbon $createdAt,
        ?int $companyId,
        string $companyName,
        ?int $userId,
        ?string $userEmail,
        string $description
    ): array {
        return [
            'type' => $type,
            'type_label' => $typeLabel,
            'created_at' => $createdAt,
            'company_id' => $companyId,
            'company_name' => $companyName,
            'user_id' => $userId,
            'user_email' => $userEmail,
            'description' => $description,
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
