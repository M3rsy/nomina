<?php

namespace App\Livewire\Jornadas;

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $schedules = [];

    /** @var array<int, array<string, mixed>> */
    public array $originalSchedules = [];

    public array $profiles = [];

    public ?int $selectedProfileId = null;

    public string $changeReason = '';

    public bool $showCreateProfile = false;

    public string $newProfileName = '';

    public bool $hasCompany = true;

    public bool $showSuccess = false;

    public bool $showHistoricalImpactWarning = false;

    public bool $confirmHistoricalImpact = false;

    public bool $showTimebandPreview = false;

    public int $historicalPayrollResultCount = 0;

    public int $historicalProcessedPeriodCount = 0;

    public ?string $latestHistoricalPeriod = null;

    /** @var array<int, array<string, string>> */
    public array $timeBandProfile = [
        ['label' => 'Ordinaria', 'start' => '06:00', 'end' => '14:00', 'rate' => '100%', 'color' => 'bg-emerald-100 text-emerald-700 border-emerald-200'],
        ['label' => 'Extra 25%', 'start' => '14:00', 'end' => '18:00', 'rate' => '+25%', 'color' => 'bg-sky-100 text-sky-700 border-sky-200'],
        ['label' => 'Extra 50%', 'start' => '18:00', 'end' => '00:00', 'rate' => '+50%', 'color' => 'bg-cyan-100 text-cyan-700 border-cyan-200'],
        ['label' => 'Extra 75%', 'start' => '00:00', 'end' => '06:00', 'rate' => '+75%', 'color' => 'bg-amber-100 text-amber-700 border-amber-200'],
    ];

    public array $technicalReadinessItems = [
        'La jornada ordinaria vigente es 06:00-14:00 y se completa con 25%, 50% y 75% en la lógica de cálculo.',
        'Domingos y feriados se tratan como jornada 100% extra en el motor de nómina.',
        'Los cambios de `is_working_day` y `base_ordinary_hours` sí pueden cambiar resultados futuros.',
        'Los tramos de recargo aceptan JSON en `banding_json`; si el JSON es inválido, el motor vuelve al template histórico.',
    ];

    public function mount(): void
    {
        $this->authorize('viewAny', WorkSchedule::class);

        $this->hasCompany = current_company_id() !== null;

        $this->loadSchedules();
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'schedules.')) {
            $this->showSuccess = false;
            $this->showHistoricalImpactWarning = false;
            $this->confirmHistoricalImpact = false;
        }

        if ($name === 'showTimebandPreview') {
            $this->showSuccess = false;
        }
    }

    public function updatedSelectedProfileId(): void
    {
        $this->showSuccess = false;
        $this->changeReason = '';
        $this->loadSchedules();
    }

    public function openCreateProfile(): void
    {
        $this->authorize('create', WorkSchedule::class);

        $this->newProfileName = '';
        $this->showCreateProfile = true;
        $this->resetValidation('newProfileName');
    }

    public function cancelCreateProfile(): void
    {
        $this->newProfileName = '';
        $this->showCreateProfile = false;
        $this->resetValidation('newProfileName');
    }

    public function createProfile(): void
    {
        $this->authorize('create', WorkSchedule::class);

        $companyId = current_company_id();

        if ($companyId === null) {
            return;
        }

        $validatedName = $this->validate([
            'newProfileName' => ['required', 'string', 'max:120'],
        ], [
            'newProfileName.required' => 'Ingresá un nombre para la plantilla.',
        ]);
        $schedules = $this->validatedSchedules((int) $companyId);

        $profile = DB::transaction(function () use ($companyId, $schedules, $validatedName): WorkScheduleProfile {
            $profile = WorkScheduleProfile::withoutCompanyScope()->create([
                'company_id' => $companyId,
                'profile_key' => $this->uniqueProfileKey((int) $companyId, $validatedName['newProfileName']),
                'name' => trim($validatedName['newProfileName']),
                'version' => 1,
                'is_active' => true,
                'created_by' => auth()->id(),
                'change_reason' => 'Creación de plantilla',
            ]);

            $this->createSchedules($profile, $schedules);

            return $profile;
        });

        $this->selectedProfileId = $profile->id;
        $this->newProfileName = '';
        $this->showCreateProfile = false;
        $this->showSuccess = true;
        $this->loadSchedules();
    }

    public function loadSchedules(): void
    {
        $companyId = current_company_id();

        if ($companyId === null) {
            $this->schedules = [];
            $this->originalSchedules = [];
            $this->historicalPayrollResultCount = 0;
            $this->historicalProcessedPeriodCount = 0;
            $this->latestHistoricalPeriod = null;

            return;
        }

        $activeProfiles = WorkScheduleProfile::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderByDesc('version')
            ->get();

        $this->profiles = $activeProfiles
            ->map(fn (WorkScheduleProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'version' => $profile->version,
            ])
            ->values()
            ->all();

        if (! $activeProfiles->contains('id', $this->selectedProfileId)) {
            $this->selectedProfileId = $activeProfiles->first()?->id;
        }

        $existing = WorkSchedule::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->when(
                $this->selectedProfileId !== null,
                fn ($query) => $query->where('work_schedule_profile_id', $this->selectedProfileId),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->orderBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

        $defaults = collect(Company::defaultWorkSchedules())->keyBy('day_of_week');

        $dayNames = [
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
        ];

        $rows = [];

        foreach ($dayNames as $day => $name) {
            $schedule = $existing->get($day);
            $default = $defaults->get($day, []);

            $rows[] = [
                'id' => $schedule?->id,
                'day_of_week' => $day,
                'day_name' => $name,
                'is_working_day' => (bool) ($schedule?->is_working_day ?? $default['is_working_day'] ?? false),
                'base_ordinary_hours' => (float) ($schedule?->base_ordinary_hours ?? $default['base_ordinary_hours'] ?? 0),
                'start_time' => $this->normalizeTime($schedule?->start_time ?? $default['start_time'] ?? null),
                'end_time' => $this->normalizeTime($schedule?->end_time ?? $default['end_time'] ?? null),
                'notes' => $schedule?->notes,
                'banding_json' => $this->serializeBandingForInput($schedule?->banding_json),
            ];
        }

        $this->schedules = $rows;
        $this->originalSchedules = collect($rows)
            ->keyBy('day_of_week')
            ->toArray();

        $this->loadHistoricalContext((int) $companyId);
    }

    public function save(): void
    {
        $this->authorize('create', WorkSchedule::class);

        $companyId = current_company_id();

        if ($companyId === null) {
            return;
        }

        if ($this->requiresHistoricalImpactConfirmation()) {
            $this->showHistoricalImpactWarning = true;

            return;
        }

        if ($this->selectedProfileId !== null) {
            $this->validate([
                'changeReason' => ['required', 'string', 'max:500'],
            ], [
                'changeReason.required' => 'Ingresá el motivo de la nueva versión.',
            ]);
        }

        $validated = $this->validatedSchedules((int) $companyId);
        $newProfile = DB::transaction(fn (): WorkScheduleProfile => $this->storeProfileVersion(
            (int) $companyId,
            $validated,
        ));

        $this->selectedProfileId = $newProfile->id;
        $this->changeReason = '';

        $this->showSuccess = true;
        $this->showHistoricalImpactWarning = false;
        $this->confirmHistoricalImpact = false;

        $this->loadSchedules();
    }

    public function confirmHistoricalSave(): void
    {
        $this->confirmHistoricalImpact = true;

        $this->save();
    }

    public function cancelHistoricalSave(): void
    {
        $this->confirmHistoricalImpact = false;
        $this->showHistoricalImpactWarning = false;
        $this->showSuccess = false;
    }

    public function getWorkingDaysCountProperty(): int
    {
        return collect($this->schedules)
            ->filter(fn (array $schedule): bool => (bool) $schedule['is_working_day'])
            ->count();
    }

    public function getWeeklyOrdinaryHoursProperty(): float
    {
        return round(
            collect($this->schedules)
                ->filter(fn (array $schedule): bool => (bool) $schedule['is_working_day'])
                ->sum('base_ordinary_hours'),
            2
        );
    }

    public function getHasHistoricalImpactProperty(): bool
    {
        return $this->hasHistoricalPayrollImpact();
    }

    public function historicalImpactSummary(): string
    {
        if ($this->historicalPayrollResultCount === 0) {
            return 'Sin resultados de nómina almacenados para esta empresa.';
        }

        $periodCount = $this->historicalProcessedPeriodCount;
        $periodText = $periodCount === 1 ? 'período procesado' : 'períodos procesados';
        $latest = $this->latestHistoricalPeriod !== null
            ? " ({$this->latestHistoricalPeriod})"
            : '';

        return "{$this->historicalPayrollResultCount} resultados de nómina en {$periodCount} {$periodText}{$latest}.";
    }

    private function validatedSchedules(int $companyId): array
    {
        $this->validate([
            'schedules.*.day_of_week' => 'required|integer|min:0|max:6',
            'schedules.*.is_working_day' => 'required|boolean',
            'schedules.*.base_ordinary_hours' => 'required|numeric|min:0|max:24',
            'schedules.*.start_time' => ['nullable', 'date_format:H:i'],
            'schedules.*.end_time' => ['nullable', 'date_format:H:i'],
            'schedules.*.notes' => 'nullable|string|max:500',
            'schedules.*.banding_json' => [
                'nullable',
                'string',
                'max:5000',
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! is_string($value) || json_decode($value, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                        $fail('El JSON de tramos debe ser válido.');
                    }
                },
            ],
        ]);

        $errors = [];

        foreach ($this->schedules as $index => $row) {
            if (! (bool) $row['is_working_day']) {
                continue;
            }

            if (blank($row['start_time'] ?? null)) {
                $errors["schedules.$index.start_time"] = 'Ingresá la hora de inicio.';
            }

            if (blank($row['end_time'] ?? null)) {
                $errors["schedules.$index.end_time"] = 'Ingresá la hora de fin.';
            }

            if (($row['start_time'] ?? null) === ($row['end_time'] ?? null)) {
                $errors["schedules.$index.end_time"] = 'La hora de fin debe ser distinta de la hora de inicio.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return collect($this->schedules)
            ->map(function (array $row) use ($companyId): array {
                $isWorkingDay = (bool) $row['is_working_day'];

                return [
                    'company_id' => $companyId,
                    'day_of_week' => (int) $row['day_of_week'],
                    'is_working_day' => $isWorkingDay,
                    'base_ordinary_hours' => $isWorkingDay
                        ? $this->normalizeBaseHours($row['base_ordinary_hours'])
                        : 0.0,
                    'start_time' => $isWorkingDay ? $this->normalizeTime($row['start_time']) : null,
                    'end_time' => $isWorkingDay ? $this->normalizeTime($row['end_time']) : null,
                    'notes' => $row['notes'] ?: null,
                    'banding_json' => $this->normalizeBandingJson($row['banding_json'] ?? null),
                ];
            })
            ->toArray();
    }

    private function requiresHistoricalImpactConfirmation(): bool
    {
        return ! $this->confirmHistoricalImpact
            && $this->hasHistoricalPayrollImpact()
            && $this->hasRiskyScheduleEdits();
    }

    private function hasHistoricalPayrollImpact(): bool
    {
        return $this->historicalPayrollResultCount > 0 || $this->historicalProcessedPeriodCount > 0;
    }

    private function hasRiskyScheduleEdits(): bool
    {
        foreach ($this->schedules as $schedule) {
            $day = (int) $schedule['day_of_week'];
            $original = $this->originalSchedules[$day] ?? null;

            if (! is_array($original)) {
                return true;
            }

            $originalIsWorking = (bool) ($original['is_working_day'] ?? false);
            $originalBase = $this->normalizeBaseHours($original['base_ordinary_hours'] ?? 0.0);
            $currentIsWorking = (bool) $schedule['is_working_day'];
            $currentBase = $this->normalizeBaseHours($schedule['base_ordinary_hours']);

            if ($originalIsWorking !== $currentIsWorking) {
                return true;
            }

            if (! $originalIsWorking && ! $currentIsWorking) {
                continue;
            }

            if ($this->normalizedBandingSignature($original['banding_json'] ?? null)
                !== $this->normalizedBandingSignature($schedule['banding_json'] ?? null)) {
                return true;
            }

            if ($currentBase !== $originalBase) {
                return true;
            }

            if ($this->normalizeTime($original['start_time'] ?? null) !== $this->normalizeTime($schedule['start_time'] ?? null)
                || $this->normalizeTime($original['end_time'] ?? null) !== $this->normalizeTime($schedule['end_time'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedBandingSignature(mixed $value): string
    {
        $bands = $this->normalizeBandingJson($value);

        if (empty($bands)) {
            return '[]';
        }

        if (! array_is_list($bands)) {
            $bands = $bands['bands'] ?? [];
        }

        if (! is_array($bands)) {
            return '[]';
        }

        $normalized = array_values(
            array_filter($bands, static fn (mixed $band): bool => is_array($band)),
        );

        usort($normalized, static fn (array $left, array $right): int => ($left['start'] ?? 0) <=> ($right['start'] ?? 0));

        return json_encode($normalized) ?: '[]';
    }

    private function loadHistoricalContext(int $companyId): void
    {
        $this->historicalPayrollResultCount = PayrollResult::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->count();

        $this->historicalProcessedPeriodCount = PayPeriod::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('status', 'processed')
            ->count();

        $latestPeriod = PayPeriod::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('status', 'processed')
            ->orderByDesc('end_date')
            ->first(['name', 'start_date', 'end_date']);

        if ($latestPeriod === null) {
            $this->latestHistoricalPeriod = null;

            return;
        }

        $this->latestHistoricalPeriod = sprintf(
            '%s (%s — %s)',
            $latestPeriod->name ?? 'Período',
            $latestPeriod->start_date->format('Y-m-d'),
            $latestPeriod->end_date->format('Y-m-d'),
        );
    }

    private function normalizeBaseHours(mixed $value): float
    {
        return round(max(0.0, min(24.0, (float) $value)), 2);
    }

    private function normalizeBandingJson(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return is_array($raw) ? $raw : null;
        }

        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    private function serializeBandingForInput(mixed $bands): string
    {
        if (! is_array($bands) || $bands === []) {
            return '';
        }

        return json_encode(array_values($bands));
    }

    private function storeProfileVersion(int $companyId, array $schedules): WorkScheduleProfile
    {
        $profile = $this->selectedProfileId === null
            ? null
            : WorkScheduleProfile::withoutCompanyScope()
                ->where('company_id', $companyId)
                ->whereKey($this->selectedProfileId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

        if ($profile === null) {
            $profileKey = 'general';
            $name = 'Jornada general';
            $version = 1;
        } else {
            $latestVersion = WorkScheduleProfile::withoutCompanyScope()
                ->where('company_id', $companyId)
                ->where('profile_key', $profile->profile_key)
                ->max('version');

            WorkScheduleProfile::withoutCompanyScope()
                ->where('company_id', $companyId)
                ->where('profile_key', $profile->profile_key)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $profileKey = $profile->profile_key;
            $name = $profile->name;
            $version = ((int) $latestVersion) + 1;
        }

        $newProfile = WorkScheduleProfile::withoutCompanyScope()->create([
            'company_id' => $companyId,
            'profile_key' => $profileKey,
            'name' => $name,
            'version' => $version,
            'is_active' => true,
            'created_by' => auth()->id(),
            'change_reason' => $profile === null ? 'Configuración inicial' : trim($this->changeReason),
        ]);

        $this->createSchedules($newProfile, $schedules);

        return $newProfile;
    }

    private function createSchedules(WorkScheduleProfile $profile, array $schedules): void
    {
        foreach ($schedules as $schedule) {
            WorkSchedule::withoutCompanyScope()->create([
                ...$schedule,
                'work_schedule_profile_id' => $profile->id,
            ]);
        }
    }

    private function uniqueProfileKey(int $companyId, string $name): string
    {
        $base = Str::slug($name) ?: 'jornada';
        $candidate = $base;
        $suffix = 2;

        while (WorkScheduleProfile::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('profile_key', $candidate)
            ->exists()) {
            $candidate = "$base-$suffix";
            $suffix++;
        }

        return $candidate;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return substr(trim($value), 0, 5);
    }

    public function render()
    {
        return view('livewire.jornadas.index');
    }
}
