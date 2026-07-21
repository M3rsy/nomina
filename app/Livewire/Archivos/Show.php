<?php

namespace App\Livewire\Archivos;

use App\Models\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Show extends Component
{
    use WithPagination;

    public const RECORD_STATUS_OPTIONS = [
        'all' => 'Todos',
        'pending' => 'Pendientes',
        'valid' => 'Válidos',
        'duplicate' => 'Duplicados',
        'out_of_period' => 'Fuera de período',
        'unknown_employee' => 'Desconocidos',
        'invalid' => 'Inválidos',
    ];

    public UploadedFile $uploadedFile;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = 'all';
        $this->resetPage();
    }

    public function fileStatusLabel(string $status): string
    {
        return match ($status) {
            'valid' => 'Válido',
            'valid_with_warnings' => 'Válido con advertencias',
            'invalid' => 'Inválido',
            'pending' => 'Pendiente',
            default => 'Sin estado',
        };
    }

    public function fileStatusClass(string $status): string
    {
        return match ($status) {
            'valid' => 'bg-emerald-100 text-emerald-800',
            'valid_with_warnings' => 'bg-amber-100 text-amber-900',
            'invalid' => 'bg-rose-100 text-rose-800',
            'pending' => 'bg-slate-100 text-slate-800',
            default => 'bg-slate-100 text-slate-500',
        };
    }

    public function recordStatusLabel(string $status): string
    {
        return match ($status) {
            'valid' => 'Válido',
            'duplicate' => 'Duplicado',
            'out_of_period' => 'Fuera de período',
            'unknown_employee' => 'Empleado desconocido',
            'invalid' => 'Inválido',
            'corrected' => 'Corregido',
            'pending' => 'Pendiente',
            default => 'Pendiente',
        };
    }

    public function recordStatusClass(string $status): string
    {
        return match ($status) {
            'valid' => 'bg-emerald-100 text-emerald-800',
            'duplicate' => 'bg-amber-100 text-amber-800',
            'out_of_period' => 'bg-orange-100 text-orange-800',
            'unknown_employee' => 'bg-rose-100 text-rose-800',
            'invalid' => 'bg-rose-100 text-rose-900',
            'corrected' => 'bg-blue-100 text-blue-800',
            'pending' => 'bg-slate-100 text-slate-800',
            default => 'bg-slate-100 text-slate-500',
        };
    }

    public function mount(UploadedFile $uploadedFile): void
    {
        $this->authorize('view', $uploadedFile);

        $this->uploadedFile = $uploadedFile->loadMissing('user', 'payPeriod');
    }

    public function render()
    {
        $statusFilter = array_key_exists($this->status, self::RECORD_STATUS_OPTIONS)
            && $this->status !== 'all'
            ? $this->status
            : '';

        $recordsQuery = $this->uploadedFile->rawMarks()
            ->when($this->search, function ($query) {
                $search = '%'.trim($this->search).'%';

                $query->where(function ($sub) use ($search) {
                    $sub->where('employee_external_id', 'like', $search)
                        ->orWhere('row_number', 'like', $search)
                        ->orWhere('source', 'like', $search);
                });
            })
            ->when($statusFilter, function ($query, string $statusFilter) {
                $query->where('status', $statusFilter);
            })
            ->orderBy('row_number');

        $records = $recordsQuery->paginate(25);

        $counts = $this->uploadedFile->rawMarks()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $summary = $this->uploadedFile->validation_summary ?? [];

        return view('livewire.archivos.show', [
            'records' => $records,
            'counts' => $counts,
            'summary' => $summary,
            'canManage' => auth()->user()?->can('manage', $this->uploadedFile) ?? false,
            'recordStatusOptions' => self::RECORD_STATUS_OPTIONS,
        ]);
    }
}
