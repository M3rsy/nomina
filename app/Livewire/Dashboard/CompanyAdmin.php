<?php

namespace App\Livewire\Dashboard;

use App\Models\Employee;
use App\Models\EmployeeRevision;
use App\Models\LoginAttempt;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class CompanyAdmin extends Component
{
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public ?int $activeEmployees = null;

    public ?int $pendingPayrolls = null;

    public ?int $errorPayrolls = null;

    public ?array $payPeriods = null;

    public ?array $recentFiles = null;

    public function mount(): void
    {
        $this->authorize('payroll.view');
    }

    public function render()
    {
        $company = current_company();

        if ($company === null) {
            $this->activeEmployees = 0;
            $this->pendingPayrolls = 0;
            $this->errorPayrolls = 0;

            return view('livewire.dashboard.company-admin', [
                'company' => null,
                'activeEmployees' => 0,
                'pendingPayrolls' => 0,
                'errorPayrolls' => 0,
                'payPeriods' => collect(),
                'recentFiles' => collect(),
                'recentActivity' => [],
            ]);
        }

        $activeEmployees = Employee::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->count();

        $payPeriodQuery = PayPeriod::query()
            ->where('company_id', $company->id)
            ->when($this->from, fn ($q) => $q->whereDate('start_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('end_date', '<=', $this->to));

        $pendingPayrolls = (clone $payPeriodQuery)
            ->whereIn('status', ['draft', 'uploaded', 'validating', 'ready'])
            ->count();

        $errorPayrolls = (clone $payPeriodQuery)
            ->where('status', 'validation_failed')
            ->count();

        $this->activeEmployees = $activeEmployees;
        $this->pendingPayrolls = $pendingPayrolls;
        $this->errorPayrolls = $errorPayrolls;

        $payPeriods = (clone $payPeriodQuery)
            ->latest('start_date')
            ->limit(10)
            ->withCount('payrollResults')
            ->withSum('payrollResults', 'worked_minutes')
            ->withSum('payrollResults', 'ordinary_minutes')
            ->withSum('payrollResults', 'extra_25_minutes')
            ->withSum('payrollResults', 'extra_50_minutes')
            ->withSum('payrollResults', 'extra_75_minutes')
            ->withSum('payrollResults', 'extra_100_minutes')
            ->get()
            ->map(fn (PayPeriod $period) => [
                'id' => $period->id,
                'name' => $period->name ?? $period->slug,
                'start_date' => $period->start_date,
                'end_date' => $period->end_date,
                'status' => $period->status,
                'results_count' => $period->payroll_results_count,
                'ordinary_hours' => (int) $period->payroll_results_sum_ordinary_minutes / 60,
                'extra_hours' => ((int) $period->payroll_results_sum_extra_25_minutes
                    + (int) $period->payroll_results_sum_extra_50_minutes
                    + (int) $period->payroll_results_sum_extra_75_minutes
                    + (int) $period->payroll_results_sum_extra_100_minutes) / 60,
                'worked_hours' => (int) $period->payroll_results_sum_worked_minutes / 60,
            ])
            ->values();

        $recentFiles = UploadedFile::query()
            ->where('company_id', $company->id)
            ->latest('created_at')
            ->limit(10)
            ->get();

        $this->payPeriods = $payPeriods->all();
        $this->recentFiles = $recentFiles->all();

        return view('livewire.dashboard.company-admin', [
            'company' => $company,
            'activeEmployees' => $activeEmployees,
            'pendingPayrolls' => $pendingPayrolls,
            'errorPayrolls' => $errorPayrolls,
            'payPeriods' => $payPeriods,
            'recentFiles' => $recentFiles,
            'recentActivity' => $this->recentActivity($company->id),
        ]);
    }

    private function recentActivity(int $companyId): array
    {
        $items = [];

        $loginAttempts = LoginAttempt::query()
            ->with('user')
            ->where('company_id', $companyId)
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('created_at')
            ->limit(15)
            ->get();

        foreach ($loginAttempts as $attempt) {
            $items[] = [
                'type_label' => 'Inicio de sesión',
                'created_at' => $attempt->created_at,
                'user_email' => $attempt->email,
                'description' => ($attempt->success ? 'Éxito' : 'Fallido').' desde IP '.$attempt->ip,
            ];
        }

        $employeeRevisions = EmployeeRevision::query()
            ->with(['user', 'employee'])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('created_at')
            ->limit(15)
            ->get();

        foreach ($employeeRevisions as $revision) {
            $items[] = [
                'type_label' => 'Empleado modificado',
                'created_at' => $revision->created_at,
                'user_email' => $revision->user?->email,
                'description' => "Campo {$revision->field}: '{$revision->old_value}' → '{$revision->new_value}'",
            ];
        }

        $payPeriods = PayPeriod::query()
            ->where('company_id', $companyId)
            ->whereNotNull('metadata')
            ->limit(50)
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

                $items[] = [
                    'type_label' => 'Nómina '.ucfirst($action),
                    'created_at' => $createdAt,
                    'user_email' => null,
                    'description' => "Período {$payPeriod->name} pasó a estado {$action}",
                ];
            }
        }

        $rawMarks = RawMark::query()
            ->where('company_id', $companyId)
            ->whereNotNull('metadata')
            ->limit(50)
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

                $items[] = [
                    'type_label' => 'Marca revisada',
                    'created_at' => $createdAt,
                    'user_email' => null,
                    'description' => "Acción {$rev['action']} en marca #{$rawMark->id} (empleado {$rawMark->employee_external_id})",
                ];
            }
        }

        usort($items, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return array_slice($items, 0, 15);
    }
}
