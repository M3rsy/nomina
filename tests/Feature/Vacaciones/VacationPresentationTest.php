<?php

use App\Livewire\Vacaciones\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vacation;
use App\Models\VacationBalanceMovement;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    app(PermissionRoleSeeder::class)->run();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('vacation management presents the workspace hierarchy and management actions', function () {
    $context = vacationPresentationContext();
    $vacation = Vacation::factory()
        ->for($context['company'])
        ->for($context['employee'])
        ->create([
            'status' => Vacation::APPROVED,
            'approved_by' => $context['manager']->id,
        ]);

    Livewire::actingAs($context['manager'])->test(Index::class)
        ->assertOk()
        ->assertSee('data-vacation-section="hero"', false)
        ->assertSee('data-vacation-section="summary"', false)
        ->assertSee('data-vacation-section="filters"', false)
        ->assertSee('data-vacation-section="records"', false)
        ->assertSeeInOrder([
            'Gestión de ausencias',
            'Vacaciones pagadas',
            'Aprobá rangos, conservá la jornada pagable y administrá el saldo del equipo con historial completo.',
            'Registros visibles',
            'Estado consultado',
            'Saldo gestionable',
            'Buscar empleado',
            'Estado',
            'Empleado',
            'Rango inclusivo',
            'Jornadas',
            'Saldo',
            'Acciones',
        ])
        ->assertSee($context['employee']->full_name)
        ->assertSee('Ajustar saldo')
        ->assertSee('Aprobar vacaciones')
        ->assertSee('wire:click="confirmCancellation('.$vacation->id.')"', false)
        ->assertSee('Cancelar');
});

test('balance adjustment presents a tenant-scoped audit movement and success confirmation', function () {
    $context = vacationPresentationContext();
    $context['manager']->update(['name' => 'Marina Auditora']);
    Carbon::setTestNow('2026-10-05 14:30:00');

    $otherCompany = Company::factory()->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create([
        'first_name' => 'Empleado',
        'last_name' => 'Ajeno',
    ]);
    VacationBalanceMovement::factory()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'days' => 99,
        'reason' => 'Movimiento de otra empresa',
    ]);

    Livewire::actingAs($context['manager'])->test(Index::class)
        ->set('employeeId', $context['employee']->id)
        ->set('adjustmentDays', 4)
        ->set('adjustmentReason', 'Reconocimiento extraordinario')
        ->call('adjustBalance')
        ->assertHasNoErrors()
        ->assertSee('Saldo ajustado correctamente.')
        ->assertSee('Movimientos recientes')
        ->assertSee($context['employee']->full_name)
        ->assertSee('+4 días')
        ->assertSee('Reconocimiento extraordinario')
        ->assertSee('Marina Auditora')
        ->assertSee('Ajuste manual')
        ->assertSee('05/10/2026 14:30')
        ->assertDontSee('Empleado Ajeno')
        ->assertDontSee('Movimiento de otra empresa');
});

test('vacation presentation keeps management actions hidden without management permission', function () {
    $context = vacationPresentationContext();
    $viewer = User::factory()->for($context['company'])->create();
    $viewer->givePermissionTo('vacations.view');

    Vacation::factory()
        ->for($context['company'])
        ->for($context['employee'])
        ->create([
            'status' => Vacation::APPROVED,
            'approved_by' => $context['manager']->id,
        ]);

    Livewire::actingAs($viewer)->test(Index::class)
        ->assertOk()
        ->assertSee('data-vacation-section="hero"', false)
        ->assertSee('data-vacation-section="records"', false)
        ->assertSee($context['employee']->full_name)
        ->assertDontSee('Ajustar saldo')
        ->assertDontSee('Aprobar vacaciones')
        ->assertDontSee('wire:click="confirmCancellation(', false)
        ->assertDontSee('Cancelar');
});

/** @return array{company: Company, manager: User, employee: Employee} */
function vacationPresentationContext(): array
{
    $company = Company::factory()->create();
    $manager = User::factory()->for($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create();

    app(CurrentCompany::class)->set($company);

    return compact('company', 'manager', 'employee');
}
