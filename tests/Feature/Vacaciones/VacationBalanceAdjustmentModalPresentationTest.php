<?php

use App\Livewire\Vacaciones\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationBalanceMovement;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    app(PermissionRoleSeeder::class)->run();
});

test('balance adjustment modal preserves its accessible structure and public Livewire contract', function () {
    $company = Company::factory()->create();
    $manager = User::factory()->for($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create();
    VacationBalanceMovement::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'days' => 8,
    ]);

    app(CurrentCompany::class)->set($company);

    $component = Livewire::actingAs($manager)->test(Index::class)
        ->call('openAdjustmentModal')
        ->set('employeeId', $employee->id)
        ->assertSee('data-vacation-modal="balance-adjustment"', false)
        ->assertSee('data-vacation-modal-section="header"', false)
        ->assertSee('data-vacation-modal-section="employee-picker"', false)
        ->assertSee('data-vacation-modal-section="impact-preview"', false)
        ->assertSee('data-vacation-modal-section="audit-evidence"', false)
        ->assertSee('data-vacation-modal-section="footer"', false)
        ->assertSee('Saldo actual')
        ->assertSee('8 días')
        ->assertSee('wire:model.live.debounce.300ms="adjustmentEmployeeSearch"', false)
        ->assertSee('wire:model.live="employeeId"', false)
        ->assertSee('wire:model.live.debounce.300ms="adjustmentDays"', false)
        ->assertSee('wire:model="adjustmentReason"', false)
        ->assertSee('wire:click="closeAdjustmentModal"', false)
        ->assertSee('wire:click="adjustBalance"', false)
        ->assertSee('wire:target="adjustBalance"', false)
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('aria-labelledby="vacation-adjustment-title"', false)
        ->assertSee('id="vacation-adjustment-title"', false)
        ->assertSee('tabindex="-1"', false)
        ->assertSee('x-init="$nextTick(() => $refs.dialog.focus())"', false)
        ->assertSee('@keydown.escape.window="$wire.closeAdjustmentModal()"', false)
        ->assertSee('aria-label="Cerrar ventana"', false);

    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $xpath = new DOMXPath($document);
    $modal = '//*[@data-vacation-modal="balance-adjustment"]';

    expect($xpath->query($modal.'//*[@data-vacation-modal-section="header"]')->length)->toBe(1)
        ->and($xpath->query($modal.'//*[@data-vacation-modal-section="footer"]')->length)->toBe(1)
        ->and($xpath->query($modal.'//button[@*[name()="wire:click"]="closeAdjustmentModal" and normalize-space()="Cerrar"]')->length)->toBe(1)
        ->and($xpath->query($modal.'//button[@*[name()="wire:click"]="adjustBalance"]')->length)->toBe(1);
});

test('balance adjustment preview derives signed and projected balances from Livewire state', function (
    int $currentBalance,
    int|string $adjustment,
    string $expectedVariation,
    string $expectedProjection,
    string $variationTreatment,
    string $projectionTreatment,
    string $variationSurface,
) {
    $company = Company::factory()->create();
    $manager = User::factory()->for($company)->create()->assignRole('company_admin');
    $employee = Employee::factory()->forCompany($company)->create();
    VacationBalanceMovement::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'days' => $currentBalance,
    ]);

    app(CurrentCompany::class)->set($company);

    $component = Livewire::actingAs($manager)->test(Index::class)
        ->call('openAdjustmentModal')
        ->set('employeeId', $employee->id)
        ->set('adjustmentDays', $adjustment);

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());
    $xpath = new DOMXPath($document);
    $modal = '//*[@data-vacation-modal="balance-adjustment"]';
    /** @var DOMElement|null $current */
    $current = $xpath->query($modal.'//*[@data-vacation-balance-value="current"]')->item(0);
    /** @var DOMElement|null $variation */
    $variation = $xpath->query($modal.'//*[@data-vacation-balance-value="variation"]')->item(0);
    /** @var DOMElement|null $projection */
    $projection = $xpath->query($modal.'//*[@data-vacation-balance-value="projected"]')->item(0);
    /** @var DOMElement|null $variationContainer */
    $variationContainer = $xpath->query($modal.'//*[@data-vacation-balance-value="variation"]/..')->item(0);

    expect($current)->not->toBeNull()
        ->and(trim($current->textContent))->toBe($currentBalance.' días')
        ->and($variation)->not->toBeNull()
        ->and(trim($variation->textContent))->toBe($expectedVariation)
        ->and($variation->getAttribute('class'))->toContain($variationTreatment)
        ->and($variationContainer)->not->toBeNull()
        ->and($variationContainer->getAttribute('class'))->toContain($variationSurface)
        ->and($projection)->not->toBeNull()
        ->and(trim($projection->textContent))->toBe($expectedProjection)
        ->and($projection->getAttribute('class'))->toContain($projectionTreatment);

    if ($variationTreatment === 'text-text-muted') {
        expect($variation->getAttribute('class'))->not->toContain('danger')
            ->and($variationContainer->getAttribute('class'))->not->toContain('danger');
    }

    if ($adjustment === 0) {
        $component->set('adjustmentReason', 'Zero must remain invalid')
            ->call('adjustBalance')
            ->assertHasErrors(['adjustmentDays']);
    }
})->with([
    'empty adjustment' => [8, '', '—', '—', 'text-text-muted', 'text-text-muted', 'border-border'],
    'zero adjustment' => [8, 0, '0 días', '8 días', 'text-text-muted', 'text-text', 'border-border'],
    'credit' => [8, 3, '+3 días', '11 días', 'text-success-strong', 'text-text', 'border-success/30'],
    'deduction' => [8, -3, '-3 días', '5 días', 'text-danger', 'text-text', 'border-danger/30'],
    'negative projected balance' => [2, -5, '-5 días', '-3 días', 'text-danger', 'text-danger', 'border-danger/30'],
]);
