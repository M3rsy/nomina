<?php

namespace App\Livewire\Usuarios;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Edit extends Component
{
    public User $user;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public string $role = 'company_admin';

    public ?int $company_id = null;

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->company_id = $user->company_id;
        $this->role = $user->getRoleNames()->first() ?? 'company_admin';
    }

    public function save(): void
    {
        $this->authorize('update', $this->user);

        $isSuperAdmin = auth()->user()->hasRole('super_admin');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
        ];

        if ($isSuperAdmin) {
            $rules['company_id'] = ['nullable', 'exists:companies,id'];
            $rules['role'] = ['required', Rule::in(['super_admin', 'company_admin'])];
        } else {
            $this->company_id = auth()->user()->company_id;
        }

        if ($this->password) {
            $rules['password'] = ['min:8'];
        }

        $validated = $this->validate($rules, [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'El correo ya está en uso.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if ($isSuperAdmin) {
            $data['company_id'] = $this->company_id;
        }

        if ($this->password) {
            $data['password'] = Hash::make($this->password);
            $data['password_changed_at'] = now();
        }

        $this->user->update($data);

        if ($isSuperAdmin) {
            $this->user->syncRoles($validated['role'] ?? $this->role);
        }

        $this->redirect('/usuarios', navigate: true);
    }

    public function render()
    {
        $isSuperAdmin = auth()->user()->hasRole('super_admin');

        return view('livewire.usuarios.edit', [
            'companies' => $isSuperAdmin ? Company::orderBy('name')->get() : null,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }
}
