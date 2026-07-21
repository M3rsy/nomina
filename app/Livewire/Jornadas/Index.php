<?php

namespace App\Livewire\Jornadas;

use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Models\WorkSchedule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $schedules = [];

    /** @var array<int, array<string, mixed>> */
    public array $originalSchedules = [];

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
        'Si más adelante se quiere configuración editable por compañía, agregar `banding_json` a `work_schedules` y usarlo en `PayrollRules/BandSplitter`.',
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

        $existing = WorkSchedule::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->orderBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

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

            $rows[] = [
                'id' => $schedule?->id,
                'day_of_week' => $day,
                'day_name' => $name,
                'is_working_day' => (bool) ($schedule?->is_working_day ?? false),
                'base_ordinary_hours' => (float) ($schedule?->base_ordinary_hours ?? 0),
                'notes' => $schedule?->notes,
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

        $validated = $this->validatedSchedules((int) $companyId);

        foreach ($validated as $data) {
            WorkSchedule::withoutCompanyScope()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'day_of_week' => $data['day_of_week'],
                ],
                [
                    'is_working_day' => $data['is_working_day'],
                    'base_ordinary_hours' => $data['base_ordinary_hours'],
                    'notes' => $data['notes'],
                ]
            );
        }

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
            'schedules.*.notes' => 'nullable|string|max:500',
        ]);

        return collect($this->schedules)
            ->map(function (array $row) use ($companyId): array {
                return [
                    'company_id' => $companyId,
                    'day_of_week' => (int) $row['day_of_week'],
                    'is_working_day' => (bool) $row['is_working_day'],
                    'base_ordinary_hours' => (bool) $row['is_working_day']
                        ? $this->normalizeBaseHours($row['base_ordinary_hours'])
                        : 0.0,
                    'notes' => $row['notes'] ?: null,
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

            if ($currentBase !== $originalBase) {
                return true;
            }
        }

        return false;
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

    public function render()
    {
        return view('livewire.jornadas.index');
    }
}
