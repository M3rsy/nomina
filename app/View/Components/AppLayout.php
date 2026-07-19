<?php

namespace App\View\Components;

use App\Models\Company;
use App\Models\User;
use App\Services\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    private const PRIMARY_NAVIGATION = [
        ['label' => 'Panel', 'route' => 'dashboard', 'active' => 'dashboard*', 'permission' => null],
        ['label' => 'Empleados', 'route' => 'empleados.index', 'active' => 'empleados.*', 'permission' => 'employees.view'],
        ['label' => 'Archivos', 'route' => 'archivos.index', 'active' => 'archivos.*', 'permission' => 'files.view'],
        ['label' => 'Nómina', 'route' => 'nomina.index', 'active' => 'nomina.*', 'permission' => 'pay_periods.view'],
    ];

    private const MANAGEMENT_NAVIGATION = [
        ['label' => 'Empresas', 'route' => 'empresas.index', 'active' => 'empresas.*', 'permission' => 'companies.view'],
        ['label' => 'Usuarios', 'route' => 'usuarios.index', 'active' => 'usuarios.*', 'permission' => 'users.view'],
        ['label' => 'Jornadas', 'route' => 'jornadas.index', 'active' => 'jornadas.*', 'permission' => 'work_schedules.view'],
        ['label' => 'Feriados', 'route' => 'feriados.index', 'active' => 'feriados.*', 'permission' => 'holidays.view'],
        ['label' => 'Auditoría', 'route' => 'auditoria.index', 'active' => 'auditoria.*', 'permission' => 'audit.view'],
        ['label' => 'Respaldos', 'route' => 'respaldos.index', 'active' => 'respaldos.*', 'permission' => 'backups.run'],
    ];

    public ?User $user;

    public bool $isSuperAdmin;

    public ?Company $currentCompany;

    /** @var Collection<int, Company> */
    public Collection $availableCompanies;

    /** @var array<int, array{label: string, route: string, active: bool}> */
    public array $primaryNavigation;

    /** @var array<int, array{label: string, route: string, active: bool}> */
    public array $managementNavigation;

    public bool $managementActive;

    public string $companyContextLabel;

    public string $accountContextLabel;

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
                ->get(['id', 'name', 'slug'])
            : collect();
        $this->primaryNavigation = $user === null ? [] : $this->visibleNavigation(self::PRIMARY_NAVIGATION, $user);
        $this->managementNavigation = $user === null ? [] : $this->visibleNavigation(self::MANAGEMENT_NAVIGATION, $user);
        $this->managementActive = collect($this->managementNavigation)->contains('active', true);
        $this->companyContextLabel = $this->currentCompany?->name ?? ($this->isSuperAdmin ? $this->allCompaniesLabel : 'Sin empresa asignada');
        $this->accountContextLabel = $this->currentCompany?->name ?? ($this->isSuperAdmin ? 'Acceso global' : 'Sin empresa asignada');
    }

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('components.layouts.app');
    }

    /**
     * @param  array<int, array{label: string, route: string, active: string, permission: string|null}>  $items
     * @return array<int, array{label: string, route: string, active: bool}>
     */
    private function visibleNavigation(array $items, User $user): array
    {
        return Collection::make($items)
            ->filter(fn (array $item): bool => $item['permission'] === null || $user->can($item['permission']))
            ->map(fn (array $item): array => [
                'label' => $item['label'],
                'route' => $item['route'],
                'active' => request()->routeIs($item['active']),
            ])
            ->values()
            ->all();
    }
}
