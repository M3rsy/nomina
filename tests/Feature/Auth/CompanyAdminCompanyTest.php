<?php

use App\Livewire\Auth\Login;
use App\Livewire\Usuarios\Create;
use App\Livewire\Usuarios\Edit;
use App\Livewire\Usuarios\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\AccountAccess;
use App\Services\CurrentCompany;
use App\Services\DatabaseSessionRevoker;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(PermissionRoleSeeder::class));

function roleUser(string $role, ?Company $company = null, array $attributes = []): User
{
    $user = User::factory()->create(['company_id' => $company?->id, ...$attributes]);
    $user->assignRole($role);

    return $user;
}

function userForm(string $component, User $actor, array $data, ?User $target = null)
{
    $test = Livewire::actingAs($actor)->test($component, $target ? ['user' => $target] : []);
    collect($data)->each(fn ($value, $property) => $test->set($property, $value));

    return $test;
}

function sessionRow(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'payload' => 'secret-payload', 'last_activity' => now()->timestamp,
    ]);
}

test('user lifecycle enforces the company admin company invariant atomically', function () {
    $super = roleUser('super_admin');
    $deleted = Company::factory()->create();
    $deleted->delete();
    $base = ['name' => 'Admin', 'password' => 'password123', 'role' => 'company_admin'];
    foreach ([null, 999999, $deleted->id] as $index => $companyId) {
        userForm(Create::class, $super, [...$base, 'email' => "invalid{$index}@example.test", 'company_id' => $companyId])
            ->call('save')->assertHasErrors('company_id');
    }
    userForm(Create::class, $super, [...$base, 'name' => 'Global', 'email' => 'global@example.test', 'role' => 'super_admin'])
        ->call('save')->assertRedirect('/usuarios');
    $inactive = Company::factory()->inactive()->create();
    userForm(Create::class, $super, [...$base, 'email' => 'inactive@example.test', 'company_id' => $inactive->id])
        ->call('save')->assertRedirect('/usuarios');
    $global = User::where('email', 'global@example.test')->sole();
    $admin = roleUser('company_admin', Company::factory()->create(), ['name' => 'Original']);
    foreach ([null, $deleted->id] as $companyId) {
        userForm(Edit::class, $super, ['name' => 'Changed', 'role' => 'company_admin', 'company_id' => $companyId], $admin)
            ->call('save')->assertHasErrors('company_id');
        expect($admin->fresh()->only('name', 'company_id'))->toBe(['name' => 'Original', 'company_id' => $admin->company_id])
            ->and($admin->fresh()->hasRole('company_admin'))->toBeTrue();
    }
    userForm(Edit::class, $super, ['name' => 'Changed', 'role' => 'company_admin', 'company_id' => null], $global)
        ->call('save')->assertHasErrors('company_id');
    expect($global->fresh()->only('name', 'company_id'))->toBe(['name' => 'Global', 'company_id' => null])
        ->and($global->fresh()->hasRole('super_admin'))->toBeTrue()
        ->and(User::where('email', 'inactive@example.test')->sole()->company_id)->toBe($inactive->id);
});

test('account denials are generic, reasoned, and revoke orphan sessions', function () {
    $company = Company::factory()->create();
    $inactive = roleUser('company_admin', $company, ['email' => 'inactive-login@example.test', 'is_active' => false]);
    $inactiveCompanyAdmin = roleUser('company_admin', Company::factory()->inactive()->create(), ['email' => 'inactive-company-login@example.test']);
    $orphan = roleUser('company_admin', null, ['email' => 'orphan-login@example.test']);
    Log::spy();
    $cases = [
        ['missing@example.test', 'credentials_invalid', null],
        [$inactive->email, 'user_inactive', $inactive->id],
        [$inactiveCompanyAdmin->email, 'company_inactive', $inactiveCompanyAdmin->id],
        [$orphan->email, 'company_assignment_missing', $orphan->id],
    ];
    foreach ($cases as [$email, $reason, $userId]) {
        Livewire::test(Login::class)->set('email', $email)->set('password', 'password')
            ->call('login')->assertHasErrors('email')->assertSee('No se pudo acceder a la cuenta.');
    }
    $this->assertGuest();
    $this->assertDatabaseHas('login_attempts', ['user_id' => $orphan->id, 'success' => false]);
    sessionRow($orphan, 'orphan-one');
    sessionRow($orphan, 'orphan-two');
    $this->actingAs($orphan)->get('/dashboard')->assertRedirect('/login')
        ->assertSessionHas('error', 'No se pudo acceder a la cuenta.');
    $this->assertGuest();
    expect(DB::table('sessions')->where('user_id', $orphan->id)->count())->toBe(0);
    $expectedWarnings = [
        'credentials_invalid' => 1,
        'user_inactive' => 1,
        'company_inactive' => 1,
        'company_assignment_missing' => 2,
    ];

    foreach ($cases as [, $reason, $userId]) {
        Log::shouldHaveReceived('warning')->with('Account access denied', [
            'event' => 'account_access_denied', 'reason' => $reason, 'user_id' => $userId,
        ])->times($expectedWarnings[$reason]);
    }
});

test('policy and tenant queries fail closed without a valid tenant', function () {
    $company = Company::factory()->create();
    $valid = roleUser('company_admin', $company);
    $same = User::factory()->forCompany($company)->create();
    $cross = User::factory()->forCompany()->create();
    $nullTarget = User::factory()->create();
    $orphan = roleUser('company_admin');
    $deletedCompany = Company::factory()->create();
    $deletedActor = roleUser('company_admin', $deletedCompany);
    $deletedCompany->delete();
    $super = roleUser('super_admin');
    $inactiveActor = roleUser('company_admin', Company::factory()->inactive()->create());
    $policy = app(UserPolicy::class);
    expect($policy->viewAny($orphan))->toBeFalse()->and($policy->create($deletedActor))->toBeFalse()
        ->and($policy->view($orphan, $nullTarget))->toBeFalse()->and($policy->update($orphan, $nullTarget))->toBeFalse()
        ->and($policy->view($valid, $same))->toBeTrue()->and($policy->update($valid, $same))->toBeTrue()
        ->and($policy->view($valid, $cross))->toBeFalse()->and($policy->view($super, $nullTarget))->toBeTrue()
        ->and($policy->viewAny($inactiveActor))->toBeFalse();
    Livewire::actingAs($orphan)->test(Index::class)->assertForbidden();
    Employee::factory()->forCompany($company)->create();
    app()->forgetInstance(CurrentCompany::class);
    expect(Employee::count())->toBe(0);
    Auth::logout();
    app()->forgetInstance(CurrentCompany::class);
    expect(Employee::count())->toBe(1);
});

test('remediation is idempotent and only super admins can recover orphans', function () {
    $validCompany = Company::factory()->create();
    $deletedCompany = Company::factory()->create();
    $nullOrphan = roleUser('company_admin');
    $deletedOrphan = roleUser('company_admin', $deletedCompany);
    $deletedCompany->delete();
    $valid = roleUser('company_admin', $validCompany);
    $inactiveCompanyAdmin = roleUser('company_admin', Company::factory()->inactive()->create());
    $super = roleUser('super_admin');
    foreach ([$nullOrphan, $deletedOrphan, $valid] as $user) {
        sessionRow($user, 'session-'.$user->id);
    }
    Log::spy();
    $this->artisan('users:disable-orphan-company-admins')->expectsOutput('Disabled 2 orphan company admin(s).')->assertSuccessful();
    $this->artisan('users:disable-orphan-company-admins')->expectsOutput('Disabled 0 orphan company admin(s).')->assertSuccessful();
    expect($nullOrphan->fresh()->only('company_id', 'is_active'))->toBe(['company_id' => null, 'is_active' => false])
        ->and($deletedOrphan->fresh()->is_active)->toBeFalse()->and($valid->fresh()->is_active)->toBeTrue()
        ->and($inactiveCompanyAdmin->fresh()->is_active)->toBeTrue()->and($super->fresh()->is_active)->toBeTrue()
        ->and(DB::table('sessions')->whereIn('user_id', [$nullOrphan->id, $deletedOrphan->id])->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $valid->id)->count())->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Orphan company admin disabled'
        && $context['event'] === 'orphan_company_admin_disabled' && $context['reason'] === 'company_assignment_missing'
        && in_array($context['user_id'], [$nullOrphan->id, $deletedOrphan->id], true) && count($context) === 3)->twice();
    Livewire::actingAs($valid)->test(Edit::class, ['user' => $nullOrphan])->assertForbidden();
    sessionRow($nullOrphan, 'stale-recovery');
    userForm(Edit::class, $super, ['company_id' => $validCompany->id, 'is_active' => true], $nullOrphan)
        ->call('save')->assertHasNoErrors()->assertRedirect('/usuarios');
    expect($nullOrphan->fresh()->only('company_id', 'is_active'))
        ->toBe(['company_id' => $validCompany->id, 'is_active' => true])
        ->and($nullOrphan->fresh()->hasRole('company_admin'))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $nullOrphan->id)->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('Company admin recovered', [
        'event' => 'company_admin_recovered', 'actor_id' => $super->id,
        'company_id' => $validCompany->id, 'user_id' => $nullOrphan->id,
    ])->once();
});

test('dual role null company super admin retains access and survives remediation', function () {
    $dual = roleUser('company_admin', null, ['email' => 'dual@example.test', 'is_active' => true, 'password' => Hash::make('password')]);
    $dual->assignRole('super_admin');

    expect(app(AccountAccess::class)->denialReason($dual->fresh()))->toBeNull();

    Livewire::test(Login::class)->set('email', 'dual@example.test')->set('password', 'password')
        ->call('login')->assertRedirect('/empresas');
    $this->assertAuthenticatedAs($dual);

    $this->actingAs($dual->fresh())->get('/empresas')->assertOk();
    Auth::logout();

    sessionRow($dual, 'dual-session');
    $this->artisan('users:disable-orphan-company-admins')->expectsOutput('Disabled 0 orphan company admin(s).')->assertSuccessful();
    expect($dual->fresh()->is_active)->toBeTrue()
        ->and($dual->fresh()->hasRole('super_admin'))->toBeTrue()
        ->and($dual->fresh()->hasRole('company_admin'))->toBeTrue();
    $this->assertDatabaseHas('sessions', ['id' => 'dual-session', 'user_id' => $dual->id]);
});

test('deactivating a company does not revoke super admin sessions', function () {
    $company = Company::factory()->create();
    $superAdmin = roleUser('super_admin', $company);

    sessionRow($superAdmin, 'super-company-session');

    $company->update(['is_active' => false]);

    expect(DB::table('sessions')->where('id', 'super-company-session')->count())->toBe(1);
});

test('recovery cannot be split across saves to bypass assignment activation and audit', function () {
    $validCompany = Company::factory()->create();
    $super = roleUser('super_admin');
    $orphan = roleUser('company_admin', null, ['is_active' => true]);
    sessionRow($orphan, 'dormant-split');

    userForm(Edit::class, $super, ['role' => 'super_admin', 'company_id' => null, 'is_active' => false], $orphan)
        ->call('save')->assertHasErrors(['company_id', 'is_active']);
    expect($orphan->fresh()->hasRole('company_admin'))->toBeTrue()
        ->and($orphan->fresh()->hasRole('super_admin'))->toBeFalse()
        ->and($orphan->fresh()->company_id)->toBe(null)
        ->and($orphan->fresh()->is_active)->toBe(true)
        ->and(DB::table('sessions')->where('user_id', $orphan->id)->count())->toBe(1);

    userForm(Edit::class, $super, ['role' => 'super_admin', 'company_id' => null, 'is_active' => true], $orphan->fresh())
        ->call('save')->assertHasErrors('company_id');
    expect($orphan->fresh()->hasRole('company_admin'))->toBeTrue()
        ->and($orphan->fresh()->company_id)->toBe(null)
        ->and($orphan->fresh()->is_active)->toBe(true);

    userForm(Edit::class, $super, ['company_id' => $validCompany->id, 'is_active' => true], $orphan->fresh())
        ->call('save')->assertHasNoErrors()->assertRedirect('/usuarios');
    expect($orphan->fresh()->is_active)->toBe(true)
        ->and($orphan->fresh()->company_id)->toBe($validCompany->id)
        ->and(DB::table('sessions')->where('user_id', $orphan->id)->count())->toBe(0);
});

test('pure super admin state transition revokes dormant sessions', function () {
    $super = roleUser('super_admin');
    $target = roleUser('super_admin');
    sessionRow($target, 'pre-deactivate');
    sessionRow($target, 'pre-deactivate-2');

    userForm(Edit::class, $super, ['is_active' => false], $target)
        ->call('save')->assertHasNoErrors()->assertRedirect('/usuarios');
    expect($target->fresh()->is_active)->toBe(false)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);

    sessionRow($target, 'dormant-reactivate');
    userForm(Edit::class, $super, ['is_active' => true], $target->fresh())
        ->call('save')->assertHasNoErrors()->assertRedirect('/usuarios');
    expect($target->fresh()->is_active)->toBe(true)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});

test('revoker failure during recovery aborts the transition leaving the account retryable', function () {
    $super = roleUser('super_admin');
    $validCompany = Company::factory()->create();
    $orphan = roleUser('company_admin', null, ['is_active' => false]);
    sessionRow($orphan, 'pre-recovery');

    $failingRevoker = new class
    {
        public function revokeUser(int $userId): int
        {
            throw new RuntimeException('session store unavailable');
        }
    };
    app()->forgetInstance(DatabaseSessionRevoker::class);
    app()->instance(DatabaseSessionRevoker::class, $failingRevoker);

    try {
        userForm(Edit::class, $super, ['company_id' => $validCompany->id, 'is_active' => true], $orphan)
            ->call('save');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('session store unavailable');
    }

    expect($orphan->fresh()->only('company_id', 'is_active'))->toBe(['company_id' => null, 'is_active' => false])
        ->and($orphan->fresh()->hasRole('company_admin'))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $orphan->id)->count())->toBe(1);

    app()->forgetInstance(DatabaseSessionRevoker::class);
    userForm(Edit::class, $super, ['company_id' => $validCompany->id, 'is_active' => true], $orphan->fresh())
        ->call('save')->assertHasNoErrors()->assertRedirect('/usuarios');
    expect($orphan->fresh()->is_active)->toBe(true)
        ->and($orphan->fresh()->company_id)->toBe($validCompany->id)
        ->and(DB::table('sessions')->where('user_id', $orphan->id)->count())->toBe(0);
});

test('session revocation honors the configured session connection and table', function () {
    config([
        'database.connections.sessions_store' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'session.connection' => 'sessions_store',
        'session.table' => 'custom_sessions',
    ]);

    $defaultConnection = DB::connection()->getName();
    DB::connection('sessions_store')->statement('CREATE TABLE custom_sessions (id TEXT PRIMARY KEY, user_id INTEGER, payload TEXT, last_activity INTEGER)');
    DB::statement('DELETE FROM sessions');
    DB::connection('sessions_store')->statement('DELETE FROM custom_sessions');

    $user = roleUser('company_admin', Company::factory()->create());
    DB::table('sessions')->insert(['id' => 'default-row', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
    DB::connection('sessions_store')->table('custom_sessions')->insert(['id' => 'session-row', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::connection('sessions_store')->table('custom_sessions')->where('user_id', $user->id)->count())->toBe(1);

    app()->forgetInstance(DatabaseSessionRevoker::class);
    app(DatabaseSessionRevoker::class)->revokeUser($user->id);

    expect(DB::connection('sessions_store')->table('custom_sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    config(['session.connection' => null, 'session.table' => 'sessions']);
    expect($defaultConnection)->toBe(DB::connection()->getName());
});
