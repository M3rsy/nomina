<?php

use App\Livewire\Vacaciones\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\Vacation;
use App\Models\VacationBalanceMovement;
use App\Models\VacationDay;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\CurrentCompany;
use App\Services\Vacations\VacationManager;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    app(PermissionRoleSeeder::class)->run();
});

test('approval snapshots only scheduled non-holiday dates and consumes one balance day each', function () {
    $context = vacationContext();
    Holiday::factory()->forCompany($context['company'])->create([
        'date' => '2026-09-15', 'name' => 'Independencia', 'is_active' => true,
    ]);
    $manager = app(VacationManager::class);
    $manager->adjustBalance($context['company'], $context['employee'], 10, 'Saldo inicial', $context['actor']);

    $vacation = $manager->approve(
        $context['company'], $context['employee'], '2026-09-10', '2026-09-15', 'Descanso anual', $context['actor'],
    );

    expect($vacation->status)->toBe(Vacation::APPROVED)
        ->and($vacation->days)->toHaveCount(4)
        ->and($vacation->days->pluck('work_date')->map->toDateString()->all())->toBe([
            '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-14',
        ])
        ->and($vacation->days->every(fn (VacationDay $day): bool => $day->planned_minutes > 0
            && strlen($day->snapshot_fingerprint) === 64
            && array_sum($day->rate_minutes) === $day->planned_minutes))->toBeTrue()
        ->and(collect($vacation->excluded_dates)->pluck('reason')->sort()->values()->all())->toBe(['holiday', 'rest_day'])
        ->and($manager->balance($context['employee']))->toBe(6)
        ->and(VacationBalanceMovement::withoutCompanyScope()->where('type', 'vacation_consumption')->count())->toBe(4);
});

test('negative balances are allowed and cancellation appends reversals without deleting history', function () {
    $context = vacationContext();
    $manager = app(VacationManager::class);
    $vacation = $manager->approve(
        $context['company'], $context['employee'], '2026-09-10', '2026-09-10', null, $context['actor'],
    );

    expect($manager->balance($context['employee']))->toBe(-1);

    $manager->cancel($vacation, 'Cambio de planificación', $context['actor']);

    expect($vacation->fresh()->status)->toBe(Vacation::CANCELLED)
        ->and($manager->balance($context['employee']))->toBe(0)
        ->and(VacationDay::withoutCompanyScope()->where('vacation_id', $vacation->id)->active()->count())->toBe(0)
        ->and(VacationBalanceMovement::withoutCompanyScope()->where('vacation_id', $vacation->id)->count())->toBe(2);
});

test('overlap and locked payroll periods prevent approval atomically', function () {
    $context = vacationContext();
    $manager = app(VacationManager::class);
    $manager->approve($context['company'], $context['employee'], '2026-09-10', '2026-09-11', null, $context['actor']);

    expect(fn () => $manager->approve(
        $context['company'], $context['employee'], '2026-09-11', '2026-09-12', null, $context['actor'],
    ))->toThrow(ValidationException::class);

    PayPeriod::factory()->forCompany($context['company'])->create([
        'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'status' => 'processed',
    ]);

    expect(fn () => $manager->approve(
        $context['company'], $context['employee'], '2026-10-01', '2026-10-01', null, $context['actor'],
    ))->toThrow(ValidationException::class)
        ->and(Vacation::withoutCompanyScope()->where('company_id', $context['company']->id)->count())->toBe(1);
});

test('vacation cancellation requires an active matching company context', function () {
    $context = vacationContext();
    $manager = app(VacationManager::class);
    $vacation = $manager->approve(
        $context['company'], $context['employee'], '2026-09-10', '2026-09-10', null, $context['actor'],
    );
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    Auth::login($superAdmin);
    app(CurrentCompany::class)->set(null);

    expect(fn () => $manager->cancel($vacation, 'Intento sin contexto', $superAdmin))
        ->toThrow(AuthorizationException::class)
        ->and($vacation->fresh()->status)->toBe(Vacation::APPROVED);

    app(CurrentCompany::class)->set($context['company']);
    $manager->cancel($vacation, 'Contexto válido', $superAdmin);

    expect($vacation->fresh()->status)->toBe(Vacation::CANCELLED);
});

test('company administrators cannot manage another company vacations', function () {
    $context = vacationContext();
    $other = Company::factory()->create();
    $otherEmployee = Employee::factory()->forCompany($other)->create();

    expect(fn () => app(VacationManager::class)->adjustBalance(
        $other, $otherEmployee, 5, 'Intento cruzado', $context['actor'],
    ))->toThrow(AuthorizationException::class);
});

test('vacations page rejects a super admin without active company context', function () {
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    Auth::login($superAdmin);
    app(CurrentCompany::class)->set(null);

    Livewire::test(Index::class)->assertForbidden();
});

test('vacation cancellation modal cannot load an id outside the active company', function () {
    $activeCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $otherVacation = Vacation::factory()->for($otherCompany)->for($otherEmployee)->create();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    Auth::login($superAdmin);
    app(CurrentCompany::class)->set($activeCompany);

    expect(fn () => Livewire::test(Index::class)->call('confirmCancellation', $otherVacation->id))
        ->toThrow(ModelNotFoundException::class);
});

test('approve vacation modal filters active employees independently from the page search', function () {
    $context = vacationContext();
    $named = Employee::factory()->forCompany($context['company'])->create([
        'first_name' => 'Alicia',
        'last_name' => 'Rivera',
        'external_id' => 'EMP-101',
        'payment_code' => 'PAY-101',
        'is_active' => true,
    ]);
    $lastNamed = Employee::factory()->forCompany($context['company'])->create([
        'first_name' => 'Bruno',
        'last_name' => 'Buscado',
        'external_id' => 'EMP-202',
        'payment_code' => 'PAY-202',
        'is_active' => true,
    ]);
    $externalCode = Employee::factory()->forCompany($context['company'])->create([
        'first_name' => 'Carla',
        'last_name' => 'Externa',
        'external_id' => 'EXT-777',
        'payment_code' => 'PAY-777',
        'is_active' => true,
    ]);
    $paymentCode = Employee::factory()->forCompany($context['company'])->create([
        'first_name' => 'Diego',
        'last_name' => 'Clave',
        'external_id' => 'EMP-888',
        'payment_code' => 'PAY-999',
        'is_active' => true,
    ]);
    $inactive = Employee::factory()->forCompany($context['company'])->create([
        'first_name' => 'Inactivo',
        'last_name' => 'Oculto',
        'external_id' => 'HIDDEN-111',
        'payment_code' => 'HIDDEN-999',
        'is_active' => false,
    ]);

    $component = Livewire::actingAs($context['actor'])->test(Index::class)
        ->set('search', 'page-level search')
        ->call('openCreateModal')
        ->assertSet('search', 'page-level search')
        ->assertSee($named->full_name)
        ->assertDontSee($inactive->full_name);

    $component->set('vacationEmployeeSearch', 'Alicia')
        ->assertSee($named->full_name)
        ->assertDontSee($lastNamed->full_name)
        ->set('vacationEmployeeSearch', 'Buscado')
        ->assertSee($lastNamed->full_name)
        ->assertDontSee($named->full_name)
        ->set('vacationEmployeeSearch', 'EXT-777')
        ->assertSee($externalCode->full_name)
        ->assertDontSee($lastNamed->full_name)
        ->set('vacationEmployeeSearch', 'PAY-999')
        ->assertSee($paymentCode->full_name)
        ->assertDontSee($externalCode->full_name)
        ->set('vacationEmployeeSearch', 'HIDDEN-111')
        ->assertDontSee($inactive->full_name)
        ->call('closeCreateModal')
        ->assertSet('vacationEmployeeSearch', '')
        ->assertSet('search', 'page-level search');
});

test('vacations page requires permissions and renders company data', function () {
    $context = vacationContext();
    app(CurrentCompany::class)->set($context['company']);

    Livewire::actingAs($context['actor'])->test(Index::class)
        ->assertOk()
        ->assertSee('Vacaciones pagadas')
        ->call('openCreateModal')
        ->assertSee($context['employee']->full_name);

    $unauthorized = User::factory()->for($context['company'])->create();
    Livewire::actingAs($unauthorized)->test(Index::class)->assertForbidden();
});

/** @return array{company: Company, actor: User, employee: Employee} */
function vacationContext(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->for($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create([
        'profile_key' => 'general', 'name' => 'Jornada general', 'version' => 1,
    ]);

    foreach (Company::defaultWorkSchedules() as $attributes) {
        WorkSchedule::factory()->forProfile($profile)->create($attributes);
    }

    EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_schedule_profile_id' => $profile->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'assigned_by' => $actor->id,
    ]);

    app(CurrentCompany::class)->set($company);

    return compact('company', 'actor', 'employee');
}
