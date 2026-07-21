<?php

namespace App\Livewire\Usuarios;

use App\Models\Company;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\DatabaseSessionRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

    public bool $is_active = true;

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->company_id = $user->company_id;
        $this->role = $user->getRoleNames()->first() ?? 'company_admin';
        $this->is_active = $user->is_active
            && app(AccountAccess::class)->denialReason($user) !== AccountAccess::COMPANY_ASSIGNMENT_MISSING;
    }

    public function save(): void
    {
        $this->authorize('update', $this->user);

        $isSuperAdmin = auth()->user()->hasRole('super_admin');
        $wasCompanyAdmin = $this->user->hasRole('company_admin');
        $wasOrphan = app(AccountAccess::class)->denialReason($this->user) === AccountAccess::COMPANY_ASSIGNMENT_MISSING;
        $wasInactive = ! $this->user->is_active;
        $needsRecovery = $isSuperAdmin && $wasCompanyAdmin && ($wasOrphan || $wasInactive);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
        ];

        if ($isSuperAdmin) {
            $rules['company_id'] = [
                Rule::requiredIf($this->role === 'company_admin' || $needsRecovery),
                'nullable',
                Rule::exists('companies', 'id')->whereNull('deleted_at'),
            ];
            $rules['role'] = ['required', Rule::in(['super_admin', 'company_admin'])];
            $rules['is_active'] = $needsRecovery ? ['boolean', 'accepted'] : ['boolean'];
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
            'company_id.required' => 'La empresa es obligatoria para recuperar la cuenta.',
            'is_active.accepted' => 'La cuenta debe activarse para recuperar la cuenta.',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if ($isSuperAdmin) {
            $data['company_id'] = $validated['company_id'];
            $data['is_active'] = $validated['is_active'];
        }

        if ($this->password) {
            $data['password'] = Hash::make($this->password);
            $data['password_changed_at'] = now();
        }

        $originalRole = $this->user->getRoleNames()->first();
        $originalCompanyId = $this->user->company_id;
        $originalIsActive = (bool) $this->user->is_active;

        DB::transaction(function () use ($data, $isSuperAdmin, $validated, $needsRecovery, $originalRole, $originalCompanyId, $originalIsActive): void {
            $this->user->update($data);

            if ($isSuperAdmin) {
                $this->user->syncRoles($validated['role']);
            }

            $roleChanged = $isSuperAdmin && $validated['role'] !== $originalRole;
            $companyChanged = $isSuperAdmin
                && array_key_exists('company_id', $data)
                && (int) ($data['company_id'] ?? 0) !== (int) ($originalCompanyId ?? 0);
            $activeChanged = array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $originalIsActive;

            if ($roleChanged || $companyChanged || $activeChanged || $needsRecovery) {
                app(DatabaseSessionRevoker::class)->revokeUser($this->user->id);
            }
        });

        if ($needsRecovery) {
            Log::warning('Company admin recovered', [
                'event' => 'company_admin_recovered', 'actor_id' => auth()->id(),
                'company_id' => $this->user->company_id, 'user_id' => $this->user->id,
            ]);
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
