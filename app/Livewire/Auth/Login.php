<?php

namespace App\Livewire\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Login extends Component
{
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

        if (! $user || ! $user->is_active) {
            RateLimiter::hit($throttleKey);
            $this->recordAttempt(false, $user);
            throw ValidationException::withMessages([
                'email' => 'Credenciales incorrectas o cuenta desactivada.',
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], remember: false)) {
            RateLimiter::hit($throttleKey);
            $this->recordAttempt(false, $user);
            throw ValidationException::withMessages([
                'email' => 'Credenciales incorrectas.',
            ]);
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
