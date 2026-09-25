<?php

use App\Livewire\Usuarios\Index;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Pest\TestSuite;
use Tests\TestCase;

uses()->beforeEach(function () {
    usersActionsTestCase()->seed(PermissionRoleSeeder::class);
});

function usersActionsTestCase(): TestCase
{
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('The current Pest test case is unavailable.');
    }

    return $test;
}

function usersActionsRoleUser(string $role, ?Company $company = null, array $attributes = []): User
{
    $user = User::factory()->create([
        'company_id' => $company?->id,
        'password' => Hash::make('password'),
        ...$attributes,
    ]);
    $user->assignRole($role);

    return $user;
}

function usersActionsSessionRow(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);
}

test('users index exposes real deactivate and delete controls according to policy', function () {
    $company = Company::factory()->create();
    $super = usersActionsRoleUser('super_admin');
    $target = usersActionsRoleUser('company_admin', $company, ['name' => 'Target User']);

    $response = usersActionsTestCase()->actingAs($super)->get(route('usuarios.index'));

    $response->assertOk();
    $response->assertSeeHtml('wire:click="deactivate('.$target->id.')"');
    $response->assertSeeHtml('wire:click="delete('.$target->id.')"');
    $response->assertDontSeeHtml('wire:click="deactivate('.$super->id.')"');
    $response->assertDontSeeHtml('wire:click="delete('.$super->id.')"');
});

test('company admin can deactivate same tenant users and revoke their sessions', function () {
    $company = Company::factory()->create();
    $admin = usersActionsRoleUser('company_admin', $company);
    $target = usersActionsRoleUser('company_admin', $company, ['is_active' => true]);
    usersActionsSessionRow($target, 'target-session');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('deactivate', $target->id)
        ->assertHasNoErrors();

    expect($target->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});

test('user management actions fail closed for self and cross tenant targets', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $admin = usersActionsRoleUser('company_admin', $companyA);
    $otherTenantUser = usersActionsRoleUser('company_admin', $companyB, ['is_active' => true]);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('deactivate', $admin->id)
        ->assertForbidden();

    Livewire::actingAs($admin)->test(Index::class)
        ->call('deactivate', $otherTenantUser->id)
        ->assertForbidden();

    Livewire::actingAs($admin)->test(Index::class)
        ->call('delete', $otherTenantUser->id)
        ->assertForbidden();

    expect($admin->fresh()->is_active)->toBeTrue()
        ->and($otherTenantUser->fresh()->is_active)->toBeTrue();
});

test('super admin can soft delete another user and revoke sessions', function () {
    $company = Company::factory()->create();
    $super = usersActionsRoleUser('super_admin');
    $target = usersActionsRoleUser('company_admin', $company);
    usersActionsSessionRow($target, 'delete-target-session');

    Livewire::actingAs($super)
        ->test(Index::class)
        ->call('delete', $target->id)
        ->assertHasNoErrors();

    expect(User::query()->whereKey($target->id)->exists())->toBeFalse()
        ->and(User::withTrashed()->whereKey($target->id)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});
