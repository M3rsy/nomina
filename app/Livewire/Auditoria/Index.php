<?php

namespace App\Livewire\Auditoria;

use App\Models\Company;
use App\Models\EmployeeRevision;
use App\Models\LoginAttempt;
use App\Models\PayPeriod;
use App\Models\RawMark;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public const TYPES = [
        'all' => 'Todos',
        'login_attempt' => 'Intentos de inicio de sesión',
        'employee_revision' => 'Cambios en empleados',
        'mark_revision' => 'Revisiones de marcas',
        'payroll_state' => 'Estados de nómina',
    ];

    #[Url]
    public string $type = 'all';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public ?string $user = null;

    #[Url]
    public ?int $company_id = null;

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function render()
    {
        $isSuper = auth()->user()->hasRole('super_admin');
        $currentCompanyId = current_company_id();

        if (! $isSuper) {
            $this->company_id = $currentCompanyId;
        }

        $companyIds = null;
        if (! $isSuper) {
            $companyIds = $currentCompanyId !== null ? [$currentCompanyId] : [];
        } elseif ($this->company_id) {
            $companyIds = [$this->company_id];
        }

        $entries = $this->collectEntries($companyIds);
        $entries = $this->applyFilters($entries);

        $page = $this->getPage();
        $perPage = 25;
        $total = count($entries);
        $items = array_slice($entries, ($page - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page']
        );

        return view('livewire.auditoria.index', [
            'entries' => $paginator,
            'types' => self::TYPES,
            'companies' => $isSuper ? Company::query()->orderBy('name')->get() : collect(),
            'isSuper' => $isSuper,
        ]);
    }

    private function collectEntries(?array $companyIds): array
    {
        $entries = [];
        $types = $this->type === 'all' ? array_keys(self::TYPES) : [$this->type];

        if (in_array('login_attempt', $types, true)) {
            LoginAttempt::query()
                ->with(['user', 'company'])
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
                ->latest('created_at')
                ->limit(500)
                ->get()
                ->each(function (LoginAttempt $attempt) use (&$entries): void {
                    $entries[] = new AuditEntry(
                        'login_attempt',
                        'Intento de inicio de sesión',
                        $attempt->created_at,
                        $attempt->company_id,
                        $attempt->company?->name ?? 'Sistema',
                        $attempt->user_id,
                        $attempt->email,
                        ($attempt->success ? 'Éxito' : 'Fallido').' desde IP '.$attempt->ip,
                        ['ip' => $attempt->ip, 'success' => $attempt->success]
                    );
                });
        }

        if (in_array('employee_revision', $types, true)) {
            EmployeeRevision::query()
                ->with(['user', 'employee.company'])
                ->when($companyIds !== null, function ($q) use ($companyIds): void {
                    $q->whereHas('employee', fn ($sq) => $sq->withoutGlobalScope('company')->whereIn('company_id', $companyIds));
                })
                ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
                ->latest('created_at')
                ->limit(500)
                ->get()
                ->each(function (EmployeeRevision $revision) use (&$entries): void {
                    $entries[] = new AuditEntry(
                        'employee_revision',
                        'Cambio en empleado',
                        $revision->created_at,
                        $revision->employee?->company_id,
                        $revision->employee?->company?->name ?? 'Desconocida',
                        $revision->user_id,
                        $revision->user?->email,
                        "Empleado #{$revision->employee_id}: campo {$revision->field} de '{$revision->old_value}' a '{$revision->new_value}'".($revision->reason ? " ({$revision->reason})" : ''),
                        ['employee_id' => $revision->employee_id, 'field' => $revision->field]
                    );
                });
        }

        if (in_array('payroll_state', $types, true)) {
            PayPeriod::withoutCompanyScope()
                ->with('company')
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->whereNotNull('metadata')
                ->limit(500)
                ->get()
                ->each(function (PayPeriod $payPeriod) use (&$entries): void {
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

                        $userId = $metadata[$action.'_by'] ?? null;
                        $user = $userId ? \App\Models\User::find($userId) : null;

                        $entries[] = new AuditEntry(
                            'payroll_state',
                            'Estado de nómina',
                            $createdAt,
                            $payPeriod->company_id,
                            $payPeriod->company?->name ?? 'Desconocida',
                            $userId,
                            $user?->email,
                            "Período {$payPeriod->name} cambió a estado {$action}",
                            ['pay_period_id' => $payPeriod->id, 'status' => $action]
                        );
                    }
                });
        }

        if (in_array('mark_revision', $types, true)) {
            RawMark::withoutCompanyScope()
                ->with('company')
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->whereNotNull('metadata')
                ->limit(500)
                ->get()
                ->each(function (RawMark $rawMark) use (&$entries): void {
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

                        $userId = $rev['user_id'] ?? null;
                        $user = $userId ? \App\Models\User::find($userId) : null;

                        $entries[] = new AuditEntry(
                            'mark_revision',
                            'Revisión de marca',
                            $createdAt,
                            $rawMark->company_id,
                            $rawMark->company?->name ?? 'Desconocida',
                            $userId,
                            $user?->email,
                            "Marca #{$rawMark->id} (empleado {$rawMark->employee_external_id}): acción {$rev['action']}",
                            ['raw_mark_id' => $rawMark->id, 'action' => $rev['action']]
                        );
                    }
                });
        }

        usort($entries, fn (AuditEntry $a, AuditEntry $b) => $b->createdAt <=> $a->createdAt);

        return $entries;
    }

    private function applyFilters(array $entries): array
    {
        if ($this->user) {
            $needle = strtolower($this->user);
            $entries = array_filter($entries, fn (AuditEntry $e) => $e->userEmail && str_contains(strtolower($e->userEmail), $needle));
        }

        return array_values($entries);
    }
}
