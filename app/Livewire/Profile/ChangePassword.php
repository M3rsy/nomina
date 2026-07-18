<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ChangePassword extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(): void
    {
        $user = Auth::user();

        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => 'La contraseña actual es obligatoria.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.required' => 'La nueva contraseña es obligatoria.',
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.different' => 'La nueva contraseña debe ser diferente a la actual.',
        ]);

        $user->forceFill([
            'password' => Hash::make($this->password),
            'password_changed_at' => now(),
        ])->save();

        // Keep current session, delete all others.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', Session::getId())
            ->delete();

        $this->reset(['current_password', 'password', 'password_confirmation']);
        session()->flash('status', 'Contraseña actualizada correctamente.');
    }

    public function render()
    {
        return view('livewire.profile.change-password');
    }
}
