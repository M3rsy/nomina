<?php

namespace App\Livewire\Nomina;

use App\Models\AuditLogEntry;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use App\Services\Payroll\PayPeriodRangeGuard;
use App\Support\Nomina\PayPeriodStatusPresentation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $name = '';

    public string $start_date = '';

    public string $end_date = '';

    public bool $showCreateForm = false;

    public ?int $deletingPeriodId = null;

    public string $deletionReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', PayPeriod::class);
    }

    public function render()
    {
        $company = current_company();

        $payPeriods = $company !== null
            ? PayPeriod::query()->orderBy('start_date', 'desc')->orderByDesc('id')->paginate(10)
            : collect();

        $visiblePeriods = $payPeriods instanceof LengthAwarePaginator
            ? $payPeriods->getCollection()
            : $payPeriods;

        $periodPresentations = $visiblePeriods->mapWithKeys(
            fn (PayPeriod $period): array => [
                $period->id => PayPeriodStatusPresentation::for($period->status),
            ],
        );
        $periodActions = $visiblePeriods->mapWithKeys(
            fn (PayPeriod $period): array => [
                $period->id => [
                    'upload' => $period->canUploadFiles() && Gate::allows('files.upload'),
                    'review' => Gate::allows('marks.manage') && Gate::allows('view', $period),
                    'delete' => Gate::allows('delete', $period),
                ],
            ],
        );

        return view('livewire.nomina.index', [
            'payPeriods' => $payPeriods,
            'hasCompany' => $company !== null,
            'periodPresentations' => $periodPresentations,
            'periodActions' => $periodActions,
            'phases' => PayPeriodStatusPresentation::phases(),
            'canCreate' => Gate::allows('create', PayPeriod::class),
        ]);
    }

    public function openCreateForm(): void
    {
        $this->authorize('create', PayPeriod::class);

        $this->showCreateForm = true;
    }

    public function closeCreateForm(): void
    {
        $this->authorize('create', PayPeriod::class);

        $this->reset('name', 'start_date', 'end_date', 'showCreateForm');
        $this->resetValidation();
    }

    public function store(PayPeriodRangeGuard $rangeGuard): void
    {
        $this->authorize('create', PayPeriod::class);
        $this->showCreateForm = true;

        $company = current_company();

        abort_if($company === null, 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'name.required' => 'Ingresá un nombre para el período.',
            'name.string' => 'El nombre del período debe ser texto.',
            'name.max' => 'El nombre no puede superar los 120 caracteres.',
            'start_date.required' => 'Ingresá la fecha de inicio.',
            'start_date.date' => 'Ingresá una fecha de inicio válida.',
            'end_date.required' => 'Ingresá la fecha de fin.',
            'end_date.date' => 'Ingresá una fecha de fin válida.',
            'end_date.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.',
        ]);

        $slug = Str::slug($validated['name']);

        if (PayPeriod::withTrashed()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->exists()) {
            $this->addError('name', 'Ya existe un período con este nombre en la empresa activa.');

            return;
        }

        try {
            $payPeriod = DB::transaction(function () use ($company, $rangeGuard, $slug, $validated): PayPeriod {
                $rangeGuard->assertAvailable(
                    $company->id,
                    $validated['start_date'],
                    $validated['end_date'],
                );

                return PayPeriod::create([
                    'company_id' => $company->id,
                    'slug' => $slug,
                    'name' => $validated['name'],
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'status' => 'draft',
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            $this->addError('start_date', $exception->getMessage());

            return;
        } catch (UniqueConstraintViolationException) {
            $this->addError('name', 'Ya existe un período con este nombre en la empresa activa.');

            return;
        }

        $this->redirectRoute('archivos.upload', [
            'pay_period_id' => $payPeriod->id,
        ], navigate: true);
    }

    public function openDeleteConfirmation(int $payPeriodId): void
    {
        $payPeriod = PayPeriod::query()->findOrFail($payPeriodId);
        $this->authorize('delete', $payPeriod);

        $this->deletingPeriodId = $payPeriod->id;
        $this->deletionReason = '';
        $this->resetValidation();
    }

    public function closeDeleteConfirmation(): void
    {
        $this->reset('deletingPeriodId', 'deletionReason');
        $this->resetValidation();
    }

    public function deletePeriod(): void
    {
        $validated = $this->validate([
            'deletingPeriodId' => ['required', 'integer'],
            'deletionReason' => ['required', 'string', 'max:500'],
        ], [
            'deletionReason.required' => 'El motivo es obligatorio.',
            'deletionReason.max' => 'El motivo no puede superar los 500 caracteres.',
        ]);

        $payPeriod = PayPeriod::query()->findOrFail($validated['deletingPeriodId']);
        $this->authorize('delete', $payPeriod);
        $reason = trim($validated['deletionReason']);

        if ($reason === '') {
            $this->addError('deletionReason', 'El motivo es obligatorio.');

            return;
        }

        DB::transaction(function () use ($payPeriod, $reason): void {
            $actorId = Auth::id();
            $files = UploadedFile::query()->where('pay_period_id', $payPeriod->id)->get();

            foreach ($files as $file) {
                $file->forceFill(['deletion_reason' => $reason, 'deleted_by' => $actorId])->saveQuietly();
                $file->delete();
                $this->auditDeletion($file, $reason, $actorId);
            }

            $payPeriod->forceFill(['deletion_reason' => $reason, 'deleted_by' => $actorId])->saveQuietly();
            $payPeriod->delete();
            $this->auditDeletion($payPeriod, $reason, $actorId);
        });

        session()->flash('success', 'La nomina y sus archivos asociados fueron eliminados.');
        $this->closeDeleteConfirmation();
    }

    private function auditDeletion(object $subject, string $reason, ?int $actorId): void
    {
        if (! Schema::hasTable('audit_entries')) {
            return;
        }

        AuditLogEntry::query()->updateOrCreate(
            ['source_type' => $subject::class, 'source_id' => $subject->getKey(), 'source_revision' => 'deleted'],
            [
                'company_id' => $subject->company_id,
                'type' => 'deletion',
                'occurred_at' => now(),
                'actor_id' => $actorId,
                'user_identifier' => Auth::user()?->email,
                'description' => 'Eliminacion de '.($subject instanceof PayPeriod ? 'nomina '.$subject->name : 'archivo '.$subject->original_name).'. Motivo: '.$reason,
                'metadata' => ['reason' => $reason, 'subject' => $subject::class],
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
            ],
        );
    }
}
