<?php

namespace App\Livewire\Archivos;

use App\Models\AuditLogEntry;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public ?int $deletingFileId = null;

    public string $deletionReason = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openDeleteConfirmation(int $fileId): void
    {
        $file = UploadedFile::query()->findOrFail($fileId);
        $this->authorize('delete', $file);

        $this->deletingFileId = $file->id;
        $this->deletionReason = '';
        $this->resetValidation();
    }

    public function closeDeleteConfirmation(): void
    {
        $this->reset('deletingFileId', 'deletionReason');
        $this->resetValidation();
    }

    public function deleteFile(): void
    {
        $validated = $this->validate([
            'deletingFileId' => ['required', 'integer'],
            'deletionReason' => ['required', 'string', 'max:500'],
        ], [
            'deletionReason.required' => 'El motivo es obligatorio.',
            'deletionReason.max' => 'El motivo no puede superar los 500 caracteres.',
        ]);

        $file = UploadedFile::query()->findOrFail($validated['deletingFileId']);
        $this->authorize('delete', $file);
        $reason = trim($validated['deletionReason']);

        if ($reason === '') {
            $this->addError('deletionReason', 'El motivo es obligatorio.');
            return;
        }

        DB::transaction(function () use ($file, $reason): void {
            $actorId = Auth::id();
            $file->rawMarks()->get()->each(function ($rawMark) use ($reason): void {
                $rawMark->status = 'deleted';
                $rawMark->notes = collect([
                    $rawMark->notes,
                    "Archivo eliminado: {$reason}",
                ])->filter()->implode("\n");
                $rawMark->save();
            });
            $file->forceFill(['deletion_reason' => $reason, 'deleted_by' => $actorId])->saveQuietly();
            $file->delete();

            if (Schema::hasTable('audit_entries')) {
                AuditLogEntry::query()->updateOrCreate(
                    ['source_type' => UploadedFile::class, 'source_id' => $file->id, 'source_revision' => 'deleted'],
                    [
                        'company_id' => $file->company_id,
                        'type' => 'deletion',
                        'occurred_at' => now(),
                        'actor_id' => $actorId,
                        'user_identifier' => Auth::user()?->email,
                        'description' => "Eliminacion de archivo {$file->original_name}. Motivo: {$reason}",
                        'metadata' => ['reason' => $reason, 'subject' => UploadedFile::class],
                        'subject_type' => UploadedFile::class,
                        'subject_id' => $file->id,
                    ],
                );
            }
        });

        session()->flash('success', 'El archivo fue eliminado.');
        $this->closeDeleteConfirmation();
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
