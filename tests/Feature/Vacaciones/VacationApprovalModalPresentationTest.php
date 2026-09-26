<?php

use App\Livewire\Vacaciones\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    app(PermissionRoleSeeder::class)->run();
});

test('approval modal presents its real controls, calculation notice, action, and accessibility contract', function () {
    $company = Company::factory()->create();
    $manager = User::factory()->for($company)->create()->assignRole('company_admin');
    Employee::factory()->forCompany($company)->create();

    app(CurrentCompany::class)->set($company);

    Livewire::actingAs($manager)->test(Index::class)
        ->call('openCreateModal')
        ->assertSee('data-vacation-modal="approval"', false)
        ->assertSee('data-vacation-modal-section="employee-picker"', false)
        ->assertSee('data-vacation-modal-section="date-range"', false)
        ->assertSee('wire:model.live.debounce.300ms="vacationEmployeeSearch"', false)
        ->assertSee('wire:model="employeeId"', false)
        ->assertSee('wire:model="startDate"', false)
        ->assertSee('wire:model="endDate"', false)
        ->assertSee('wire:model="notes"', false)
        ->assertSee('El cálculo de jornadas pagables, descansos y feriados se realiza en el servidor al aprobar', false)
        ->assertSee('wire:click="approve"', false)
        ->assertSee('Aprobar vacaciones')
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('aria-labelledby="vacation-create-title"', false)
        ->assertSee('id="vacation-create-title"', false)
        ->assertSee('tabindex="-1"', false)
        ->assertSee('x-init="$nextTick(() => $refs.dialog.focus())"', false)
        ->assertSee('@keydown.escape.window="$wire.closeCreateModal()"', false)
        ->assertSee('aria-label="Cerrar ventana"', false);
});
