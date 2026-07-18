<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('disabling user kills their existing session', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
        'is_active' => true,
    ]);
    $user->assignRole('company_admin');

    $this->actingAs($user);
    Session::put('login_at', now());

    // Simulate a database session row for this user.
    DB::table('sessions')->insert([
        'id' => Session::getId(),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => serialize(Session::all()),
        'last_activity' => now()->timestamp,
    ]);

    // Another session for the same user.
    DB::table('sessions')->insert([
        'id' => 'other-session-id',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => 'test',
        'last_activity' => now()->timestamp,
    ]);

    $user->is_active = false;
    $user->save();

    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
    $response->assertSessionHas('error', 'Cuenta desactivada');
    $this->assertGuest();
    $this->assertDatabaseMissing('sessions', ['id' => 'other-session-id']);
});

test('changing password invalidates other sessions but keeps current', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);
    $user->assignRole('super_admin');

    $this->actingAs($user);
    Session::put('login_at', now()->subMinute());

    $currentSessionId = Session::getId();

    DB::table('sessions')->insert([
        'id' => $currentSessionId,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => base64_encode(serialize(Session::all())),
        'last_activity' => now()->timestamp,
    ]);

    DB::table('sessions')->insert([
        'id' => 'other-session-id',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => 'test',
        'last_activity' => now()->timestamp,
    ]);

    Livewire::test(\App\Livewire\Profile\ChangePassword::class)
        ->set('current_password', 'password')
        ->set('password', 'newpassword')
        ->set('password_confirmation', 'newpassword')
        ->call('save');

    $this->assertDatabaseMissing('sessions', ['id' => 'other-session-id']);
    $this->assertDatabaseHas('sessions', ['id' => $currentSessionId]);

    $user->refresh();
    expect($user->password_changed_at)->not->toBeNull();
});
