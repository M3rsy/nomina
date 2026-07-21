<?php

namespace App\Livewire\Empleados;

use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Edit extends Component
{
    public Employee $employee;

    public ?int $company_id = null;

    public string $external_id = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $dni = '';

    public ?string $sex = null;

    public ?string $birth_date = null;

    public ?string $address = null;

    public ?string $phone = null;

    public ?string $job_title = null;

    public ?string $expected_salary = null;

    public ?string $hired_at = null;

    public ?string $notes = null;

    public ?int $schedule_profile_id = null;

    public string $schedule_effective_from = '';

    public string $schedule_reason = '';

    public function mount(Employee $employee): void
    {
        $this->authorize('update', $employee);

        $this->employee = $employee;
        $this->company_id = $employee->company_id;
        $this->external_id = $employee->external_id;
        $this->first_name = $employee->first_name;
        $this->last_name = $employee->last_name;
        $this->dni = $employee->dni ?? '';
        $this->sex = $employee->sex;
        $this->birth_date = $employee->birth_date?->format('Y-m-d');
        $this->address = $employee->address;
        $this->phone = $employee->phone;
        $this->job_title = $employee->job_title;
        $this->expected_salary = $employee->expected_salary !== null ? (string) $employee->expected_salary : null;
        $this->hired_at = $employee->hired_at?->format('Y-m-d');
        $this->notes = $employee->notes;
        $this->schedule_effective_from = now()->toDateString();

        if (! $employee->scheduleAssignments()->exists()) {
            $this->schedule_profile_id = $this->scheduleProfiles()->first()?->id;
        }
    }

    public function updatedCompanyId(): void
    {
        $this->schedule_profile_id = $this->scheduleProfiles()->first()?->id;
    }

    public function save(): void
    {
        $this->authorize('update', $this->employee);

        $isSuperAdmin = auth()->user()->hasRole('super_admin');
        $companyId = $isSuperAdmin ? ($this->company_id ?? $this->employee->company_id) : auth()->user()->company_id;
        $requiresSchedule = $companyId !== $this->employee->company_id
            || ! $this->employee->scheduleAssignments()->exists();
        $assigningSchedule = $this->schedule_profile_id !== null;

        $rules = [
            'external_id' => ['required', 'string', 'max:50', Rule::unique('employees', 'external_id')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($this->employee->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'dni' => ['nullable', 'string', 'max:32', 'regex:/^\d*$/'],
            'sex' => ['nullable', 'in:M,F,O'],
            'birth_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'expected_salary' => ['nullable', 'numeric', 'decimal:0,2'],
            'hired_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];

        if ($requiresSchedule || $assigningSchedule) {
            $rules['schedule_profile_id'] = [
                'required',
                Rule::exists('work_schedule_profiles', 'id')->where(
                    fn ($query) => $query->where('company_id', $companyId)->where('is_active', true),
                ),
            ];
            $rules['schedule_effective_from'] = ['required', 'date'];
            $rules['schedule_reason'] = ['required', 'string', 'max:255'];
        }

        if ($isSuperAdmin) {
            $rules['company_id'] = ['nullable', 'exists:companies,id'];
        } else {
            $rules['company_id'] = ['required', 'in:'.auth()->user()->company_id];
        }

        $validated = $this->validate($rules, $this->messages());

        $validated['company_id'] = $companyId;
        $validated['metadata'] = $this->employee->metadata;

        $profileId = $validated['schedule_profile_id'] ?? null;
        $effectiveFrom = $validated['schedule_effective_from'] ?? null;
        $reason = $validated['schedule_reason'] ?? null;
        unset($validated['schedule_profile_id'], $validated['schedule_effective_from'], $validated['schedule_reason']);

        DB::transaction(function () use ($validated, $profileId, $effectiveFrom, $reason): void {
            $this->employee->update($validated);

            if ($profileId === null || $effectiveFrom === null || $reason === null) {
                return;
            }

            $profile = WorkScheduleProfile::withoutCompanyScope()->findOrFail($profileId);
            app(EmployeeScheduleAssigner::class)->assign(
                $this->employee,
                $profile,
                $effectiveFrom,
                $reason,
                auth()->user(),
            );
        });

        $this->redirect('/empleados', navigate: true);
    }

    public function render()
    {
        $isSuperAdmin = auth()->user()->hasRole('super_admin');

        return view('livewire.empleados.edit', [
            'companies' => $isSuperAdmin ? Company::orderBy('name')->get() : null,
            'isSuperAdmin' => $isSuperAdmin,
            'scheduleProfiles' => $this->scheduleProfiles(),
            'scheduleAssignments' => $this->employee->scheduleAssignments()
                ->with('profile')
                ->orderByDesc('effective_from')
                ->get(),
        ]);
    }

    private function scheduleProfiles()
    {
        $companyId = auth()->user()->hasRole('super_admin')
            ? ($this->company_id ?? $this->employee->company_id)
            : auth()->user()->company_id;

        return WorkScheduleProfile::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderByDesc('version')
            ->get();
    }

    private function messages(): array
    {
        return [
            'external_id.required' => 'El código de empleado es obligatorio.',
            'external_id.unique' => 'El código de empleado ya existe en esta empresa.',
            'first_name.required' => 'El nombre es obligatorio.',
            'last_name.required' => 'El apellido es obligatorio.',
            'dni.regex' => 'La identidad debe contener solo números.',
            'sex.in' => 'El sexo debe ser M, F u O.',
            'expected_salary.decimal' => 'El salario esperado debe tener hasta 2 decimales.',
            'company_id.in' => 'No está autorizado para editar empleados de esa empresa.',
            'schedule_profile_id.required' => 'Seleccioná una jornada para el empleado.',
            'schedule_profile_id.exists' => 'La jornada seleccionada no está disponible para esta empresa.',
            'schedule_effective_from.required' => 'Ingresá desde qué fecha rige la jornada.',
            'schedule_reason.required' => 'Ingresá el motivo de la asignación.',
        ];
    }
}
