<?php

namespace App\Livewire\Archivos;

use App\Models\UploadedFile;
use App\Models\PayPeriod;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public const STATUS_OPTIONS = [
        'all' => 'Todos',
        'pending' => 'Pendiente',
        'valid' => 'Válido',
        'valid_with_warnings' => 'Con advertencias',
        'invalid' => 'Con errores',
    ];

    public const PER_PAGE = 10;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public ?int $pay_period_id = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingPayPeriodId(): void
    {
        $this->resetPage();
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = 'all';
        $this->pay_period_id = null;
        $this->from = '';
        $this->to = '';

        $this->resetPage();
    }

    public function statusLabel(string $status): string
    {
        return self::STATUS_OPTIONS[$status] ?? 'Estado desconocido';
    }

    public function statusClasses(string $status): string
    {
        return match ($status) {
            'valid' => 'bg-emerald-100 text-emerald-800',
            'valid_with_warnings' => 'bg-amber-100 text-amber-900',
            'invalid' => 'bg-rose-100 text-rose-800',
            'pending' => 'bg-slate-100 text-slate-800',
            default => 'bg-slate-100 text-slate-500',
        };
    }

    public function render()
    {
        $this->authorize('viewAny', UploadedFile::class);

        $payPeriodId = request()->query('pay_period_id', $this->pay_period_id);
        $payPeriodId = match (true) {
            is_int($payPeriodId) && $payPeriodId > 0 => $payPeriodId,
            is_string($payPeriodId) && ctype_digit($payPeriodId) => (int) $payPeriodId,
            default => null,
        };

        $statusFilter = array_key_exists($this->status, self::STATUS_OPTIONS)
            && $this->status !== 'all'
            ? $this->status
            : '';

        $filesQuery = UploadedFile::query()
            ->with(['payPeriod'])
            ->withCount('rawMarks')
            ->when($this->search, function ($query) {
                $search = '%'.trim($this->search).'%';

                $query->where(function ($sub) use ($search) {
                    $sub->where('original_name', 'like', $search)
                        ->orWhereHas('payPeriod', function ($periodQuery) use ($search) {
                            $periodQuery->where('name', 'like', $search);
                        });
                });
            })
            ->when($statusFilter, function ($query, string $statusFilter) {
                $query->where('status', $statusFilter);
            })
            ->when($payPeriodId, function ($query) use ($payPeriodId) {
                $query->where('pay_period_id', $payPeriodId);
            })
            ->when($this->from, function ($query) {
                $query->whereDate('created_at', '>=', $this->from);
            })
            ->when($this->to, function ($query) {
                $query->whereDate('created_at', '<=', $this->to);
            });

        $statusCounts = (clone $filesQuery)
            ->select('uploaded_files.status')
            ->selectRaw('count(*) as total')
            ->groupBy('uploaded_files.status')
            ->pluck('total', 'status')
            ->toArray();

        $files = $filesQuery
            ->orderBy('created_at', 'desc')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $payPeriods = PayPeriod::query()
            ->select('id', 'name', 'start_date', 'end_date')
            ->orderBy('start_date', 'desc')
            ->get();

        return view('livewire.archivos.index', [
            'files' => $files,
            'payPeriods' => $payPeriods,
            'statusOptions' => self::STATUS_OPTIONS,
            'statusCounts' => $statusCounts,
        ]);
    }
}
