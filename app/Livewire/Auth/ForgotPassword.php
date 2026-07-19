<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ForgotPassword extends Component
{
    public string $email = '';

    public string $status = '';

    public function sendResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no es válido.',
        ]);

        $status = Password::sendResetLink(
            $this->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            $this->status = 'Le enviamos un enlace para restablecer su contraseña.';
            $this->reset('email');
        } else {
            throw ValidationException::withMessages([
                'email' => trans($status),
            ]);
        }
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
