<?php

namespace App\Livewire\Empleados;

use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\GeneralWorkScheduleResolver;
use App\Services\Employees\EmployeePositionAssigner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Edit extends Component
{
    public Employee $employee;

    public ?int $company_id = null;

    public string $external_id = '';

    public string $payment_code = '';

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

    public string $position_effective_from = '';

    public string $position_reason = '';

    public function mount(Employee $employee): void
    {
        $this->authorize('update', $employee);

        $this->employee = $employee;
        $this->company_id = $employee->company_id;
        $this->external_id = $employee->external_id;
        $this->payment_code = $employee->payment_code ?? '';
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
        $this->position_effective_from = now()->toDateString();

        if (! $employee->scheduleAssignments()->exists()) {
            $this->schedule_profile_id = $this->scheduleProfiles()->first()?->id;
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->employee);

        $companyId = $this->employee->company_id;
        $requiresSchedule = ! $this->employee->scheduleAssignments()->exists();
        $assigningSchedule = $this->schedule_profile_id !== null;
        $normalizedTitle = trim((string) $this->job_title);
        $normalizedTitle = $normalizedTitle === '' ? null : $normalizedTitle;
        $currentTitle = trim((string) $this->employee->job_title);
        $currentTitle = $currentTitle === '' ? null : $currentTitle;
        $changingPosition = $normalizedTitle !== $currentTitle;

        $rules = [
            'external_id' => ['required', 'string', 'max:50', Rule::unique('employees', 'external_id')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($this->employee->id)],
            'payment_code' => ['nullable', 'string', 'max:50'],
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
                    fn ($query) => $query->where('company_id', $companyId),
                ),
            ];
            $rules['schedule_effective_from'] = ['required', 'date'];
            $rules['schedule_reason'] = ['required', 'string', 'max:255'];
        }

        if ($changingPosition) {
            $rules['job_title'] = ['required', 'string', 'max:100'];
            $rules['position_effective_from'] = [
                'required',
                'date',
                Rule::unique('employee_position_assignments', 'effective_from')
                    ->where(fn ($query) => $query->where('employee_id', $this->employee->id)),
            ];
            $rules['position_reason'] = ['required', 'string', 'max:255'];
        }

        $rules['company_id'] = ['required', 'integer', Rule::in([$companyId])];

        $validated = $this->validate($rules, $this->messages());

        $validated['payment_code'] = blank($validated['payment_code'] ?? null)
            ? null
            : $validated['payment_code'];
        $validated['job_title'] = $normalizedTitle;
        $validated['company_id'] = $companyId;
        $validated['metadata'] = $this->employee->metadata;

        $profileId = $validated['schedule_profile_id'] ?? null;
        $effectiveFrom = $validated['schedule_effective_from'] ?? null;
        $reason = $validated['schedule_reason'] ?? null;
        $newTitle = $normalizedTitle;
        $positionEffectiveFrom = $validated['position_effective_from'] ?? null;
        $positionReason = $validated['position_reason'] ?? null;
        unset(
            $validated['schedule_profile_id'],
            $validated['schedule_effective_from'],
            $validated['schedule_reason'],
            $validated['position_effective_from'],
            $validated['position_reason'],
        );
        if ($changingPosition) {
            unset($validated['job_title']);
        }

        DB::transaction(function () use (
            $profileId,
            $effectiveFrom,
            $reason,
            $validated,
            $companyId,
            $changingPosition,
            $newTitle,
            $positionEffectiveFrom,
            $positionReason,
        ): void {
            if ($profileId === null || $effectiveFrom === null || $reason === null) {
                $this->employee->update($validated);
            } else {
                $profile = app(GeneralWorkScheduleResolver::class)->resolve($companyId, $effectiveFrom);
                if ($profile->id !== $profileId) {
                    throw ValidationException::withMessages([
                        'schedule_profile_id' => 'La jornada seleccionada no es la jornada general vigente para esa fecha.',
                    ]);
                }

                app(EmployeeScheduleAssigner::class)->assign(
                    $this->employee,
                    $profile,
                    $effectiveFrom,
                    $reason,
                    Auth::user(),
                    mutateEmployee: fn (Employee $lockedEmployee) => $lockedEmployee->update($validated),
                    allowHistoricalProfile: true,
                );
            }

            if ($changingPosition && $newTitle !== null && $positionEffectiveFrom !== null && $positionReason !== null) {
                app(EmployeePositionAssigner::class)->assign(
                    $this->employee,
                    $newTitle,
                    $positionEffectiveFrom,
                    $positionReason,
                    Auth::user(),
                );
            }
        });

        $this->redirect('/empleados', navigate: true);
    }

    public function updatedScheduleEffectiveFrom(): void
    {
        if (! $this->employee->scheduleAssignments()->exists()) {
            $this->schedule_profile_id = $this->scheduleProfiles()->first()?->id;
        }
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $isSuperAdmin = $user->hasRole('super_admin');

        return view('livewire.empleados.edit', [
            'isSuperAdmin' => $isSuperAdmin,
            'scheduleProfiles' => $this->scheduleProfiles(),
            'scheduleAssignments' => $this->employee->scheduleAssignments()
                ->with('profile')
                ->orderByDesc('effective_from')
                ->get(),
            'positionAssignments' => $this->employee->positionAssignments()
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    private function scheduleProfiles()
    {
        if ($this->schedule_effective_from === '') {
            return collect();
        }

        try {
            return collect([app(GeneralWorkScheduleResolver::class)->resolve(
                $this->employee->company_id,
                $this->schedule_effective_from,
            )]);
        } catch (ValidationException) {
            return collect();
        }
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
            'company_id.in' => 'La empresa del empleado no puede cambiarse porque forma parte de su historial.',
            'schedule_profile_id.required' => 'Seleccioná una jornada para el empleado.',
            'schedule_profile_id.exists' => 'La jornada seleccionada no está disponible para esta empresa.',
            'schedule_effective_from.required' => 'Ingresá desde qué fecha rige la jornada.',
            'schedule_reason.required' => 'Ingresá el motivo de la asignación.',
            'job_title.required' => 'Ingresá el nuevo cargo.',
            'position_effective_from.required' => 'Ingresá desde qué fecha rige el cargo.',
            'position_effective_from.unique' => 'Ya existe un cargo asignado desde esa fecha.',
            'position_reason.required' => 'Ingresá el motivo del cambio de cargo.',
        ];
    }
}
