<?php

namespace App\Livewire\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\DatabaseSessionRevoker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Login extends Component
{
    private const CREDENTIALS_INVALID = 'credentials_invalid';

    public string $email = '';

    public string $password = '';

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no es válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        $throttleKey = strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->recordAttempt(false);
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos fallidos. Intente de nuevo en '.RateLimiter::availableIn($throttleKey).' segundos.',
            ]);
        }

        $user = User::where('email', $this->email)->first();
        $reason = $user ? app(AccountAccess::class)->denialReason($user) : self::CREDENTIALS_INVALID;

        if (! $user || $reason !== null) {
            $this->deny($throttleKey, $user, $reason);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], remember: false)) {
            $this->deny($throttleKey, $user, self::CREDENTIALS_INVALID);
        }

        RateLimiter::clear($throttleKey);
        $this->recordAttempt(true, $user);

        Session::regenerate();
        session(['login_at' => now()]);

        $route = match (true) {
            $user->hasRole('super_admin') => '/empresas',
            $user->hasRole('company_admin') => '/usuarios',
            default => '/dashboard',
        };

        $this->redirect($route, navigate: true);
    }

    private function deny(string $throttleKey, ?User $user, string $reason): never
    {
        RateLimiter::hit($throttleKey);
        $this->recordAttempt(false, $user);
        if ($user && $reason !== self::CREDENTIALS_INVALID) {
            app(DatabaseSessionRevoker::class)->revokeUser($user->id);
        }
        Log::warning('Account access denied', [
            'event' => 'account_access_denied', 'reason' => $reason, 'user_id' => $user?->id,
        ]);

        throw ValidationException::withMessages(['email' => AccountAccess::USER_MESSAGE]);
    }

    protected function recordAttempt(bool $success, ?User $user = null): void
    {
        LoginAttempt::create([
            'user_id' => $user?->id,
            'company_id' => $user?->company_id,
            'email' => $this->email,
            'ip' => request()->ip() ?? 'unknown',
            'user_agent' => request()->userAgent(),
            'success' => $success,
        ]);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
