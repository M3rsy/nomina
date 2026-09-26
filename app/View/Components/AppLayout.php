<?php

namespace App\View\Components;

use App\Models\Company;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    private const NAVIGATION_GROUPS = [
        [
            'label' => 'Principal',
            'items' => [
                ['label' => 'Panel', 'route' => 'dashboard', 'active' => 'dashboard*', 'permission' => null, 'icon' => 'panel'],
            ],
        ],
        [
            'label' => 'Nómina & Tiempo',
            'items' => [
                ['label' => 'Empleados', 'route' => 'empleados.index', 'active' => 'empleados.*', 'permission' => 'employees.view', 'icon' => 'employees'],
                ['label' => 'Nómina', 'route' => 'nomina.index', 'active' => 'nomina.*', 'permission' => 'pay_periods.view', 'icon' => 'payroll'],
                ['label' => 'Archivos', 'route' => 'archivos.index', 'active' => 'archivos.*', 'permission' => 'files.view', 'icon' => 'files'],
                ['label' => 'Jornadas', 'route' => 'jornadas.index', 'active' => 'jornadas.*', 'permission' => 'work_schedules.view', 'icon' => 'schedule'],
                ['label' => 'Vacaciones', 'route' => 'vacaciones.index', 'active' => 'vacaciones.*', 'permission' => 'vacations.view', 'icon' => 'vacations'],
                ['label' => 'Feriados', 'route' => 'feriados.index', 'active' => 'feriados.*', 'permission' => 'holidays.view', 'icon' => 'holidays'],
            ],
        ],
        [
            'label' => 'Administración',
            'items' => [
                ['label' => 'Empresas', 'route' => 'empresas.index', 'active' => 'empresas.*', 'permission' => 'companies.view', 'icon' => 'companies'],
                ['label' => 'Usuarios', 'route' => 'usuarios.index', 'active' => 'usuarios.*', 'permission' => 'users.view', 'icon' => 'users'],
                ['label' => 'Auditoría', 'route' => 'auditoria.index', 'active' => 'auditoria.*', 'permission' => 'audit.view', 'icon' => 'audit'],
                ['label' => 'Respaldos', 'route' => 'respaldos.index', 'active' => 'respaldos.*', 'permission' => 'backups.manage-global', 'icon' => 'backups'],
            ],
        ],
    ];

    public ?User $user;

    public bool $isSuperAdmin;

    public ?Company $currentCompany;

    /** @var Collection<int, Company> */
    public Collection $availableCompanies;

    /** @var array<int, array{label: string, items: array<int, array{label: string, route: string, active: bool, icon: string}>}> */
    public array $navigationGroups;

    public string $companyContextLabel;

    public string $companyLegalIdLabel;

    public string $accountContextLabel;

    public string $roleLabel;

    public string $allCompaniesLabel = 'Todas las empresas';

    public string $changePasswordLabel = 'Cambiar contraseña';

    public function __construct(CurrentCompany $currentCompany)
    {
        /** @var User|null $user */
        $user = Auth::user();

        $this->user = $user;
        $this->isSuperAdmin = $user?->hasRole('super_admin') ?? false;
        $this->currentCompany = $user === null ? null : $currentCompany->get();
        $this->availableCompanies = $this->isSuperAdmin
            ? Company::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'legal_id', 'slug'])
            : collect();
        $this->navigationGroups = $user === null ? [] : $this->visibleNavigationGroups($user);
        $this->companyContextLabel = $this->currentCompany?->name ?? ($this->isSuperAdmin ? $this->allCompaniesLabel : 'Sin empresa asignada');
        $this->companyLegalIdLabel = $this->currentCompany?->legal_id
            ? 'RTN: '.$this->currentCompany->legal_id
            : ($this->currentCompany ? 'RTN no registrado' : ($this->isSuperAdmin ? 'Contexto global' : 'Sin empresa asignada'));
        $this->accountContextLabel = $this->currentCompany?->name ?? ($this->isSuperAdmin ? 'Acceso global' : 'Sin empresa asignada');
        $this->roleLabel = match (true) {
            $this->isSuperAdmin => 'Superadministrador',
            $user?->hasRole('company_admin') => 'Administrador de empresa',
            default => 'Sin rol asignado',
        };
    }

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('components.layouts.app');
    }

    /**
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string, active: bool, icon: string}>}>
     */
    private function visibleNavigationGroups(User $user): array
    {
        return Collection::make(self::NAVIGATION_GROUPS)
            ->map(function (array $group) use ($user): array {
                $items = Collection::make($group['items'])
                    ->filter(fn (array $item): bool => $item['permission'] === null || ($item['permission'] === 'backups.manage-global'
                        ? AppServiceProvider::canManageGlobalBackups($user)
                        : $user->can($item['permission'])))
                    ->map(fn (array $item): array => [
                        'label' => $item['label'],
                        'route' => $item['route'],
                        'active' => request()->routeIs($item['active']),
                        'icon' => $item['icon'],
                    ])
                    ->values()
                    ->all();

                return ['label' => $group['label'], 'items' => $items];
            })
            ->filter(fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();
    }
}
