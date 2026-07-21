<?php

namespace App\Livewire\Usuarios;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Create extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'company_admin';

    public ?int $company_id = null;

    public function mount(): void
    {
        $this->company_id = current_company_id();
    }

    public function save(): void
    {
        $this->authorize('create', User::class);

        $isSuperAdmin = auth()->user()->hasRole('super_admin');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'min:8'],
            'role' => ['required', Rule::in($isSuperAdmin ? ['super_admin', 'company_admin'] : ['company_admin'])],
        ];

        if ($isSuperAdmin) {
            $rules['company_id'] = [
                Rule::requiredIf($this->role === 'company_admin'),
                'nullable',
                Rule::exists('companies', 'id')->whereNull('deleted_at'),
            ];
        } else {
            $rules['company_id'] = ['required', 'in:'.auth()->user()->company_id];
        }

        $validated = $this->validate($rules, [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'El correo ya está en uso.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'role.in' => 'El rol seleccionado no es válido.',
        ]);

        DB::transaction(function () use ($validated): void {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'company_id' => $validated['company_id'],
                'is_active' => true,
            ]);

            $user->syncRoles($validated['role']);
        });

        $this->redirect('/usuarios', navigate: true);
    }

    public function render()
    {
        $isSuperAdmin = auth()->user()->hasRole('super_admin');

        return view('livewire.usuarios.create', [
            'companies' => $isSuperAdmin ? Company::orderBy('name')->get() : null,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }
}
