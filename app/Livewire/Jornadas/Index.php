<?php

namespace App\Livewire\Jornadas;

use App\Models\WorkSchedule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $schedules = [];

    public bool $showSuccess = false;

    public function mount(): void
    {
        $this->authorize('viewAny', WorkSchedule::class);

        $this->loadSchedules();
    }

    public function loadSchedules(): void
    {
        $companyId = current_company_id();

        if ($companyId === null) {
            $this->schedules = [];

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
    }

    public function save(): void
    {
        $this->authorize('create', WorkSchedule::class);

        $companyId = current_company_id();

        if ($companyId === null) {
            return;
        }

        $validated = collect($this->schedules)->map(function (array $row) use ($companyId): array {
            return [
                'company_id' => $companyId,
                'day_of_week' => (int) $row['day_of_week'],
                'is_working_day' => (bool) $row['is_working_day'],
                'base_ordinary_hours' => (float) $row['base_ordinary_hours'],
                'notes' => $row['notes'] ?: null,
            ];
        })->toArray();

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
        $this->loadSchedules();
    }

    public function render()
    {
        return view('livewire.jornadas.index');
    }
}
