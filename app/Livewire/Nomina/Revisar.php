<?php

namespace App\Livewire\Nomina;

use App\Models\Employee;
use App\Models\JustifiedAbsence;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Services\Attendance\AttendanceReviewQuery;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\PayrollReadinessChecker;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\Payroll\PayPeriodReopener;
use App\Services\PayrollRules;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Revisar extends Component
{
    use WithPagination;

    public PayPeriod $payPeriod;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public ?int $uploaded_file_id = null;

    public bool $showEditModal = false;

    public ?int $editRawMarkId = null;

    public string $editEventAt = '';

    public ?string $editWarning = null;

    public bool $showDeleteModal = false;

    public ?int $deleteRawMarkId = null;

    public bool $showAssignModal = false;

    public ?int $assignRawMarkId = null;

    public ?int $assignEmployeeId = null;

    public bool $assignApplyAll = false;

    public bool $showAbsencesModal = false;

    public ?int $absenceEmployeeId = null;

    public string $absenceDate = '';

    public string $absenceReason = 'permission';

    public ?string $absenceNotes = null;

    public bool $showReadyConfirm = false;

    public ?string $readyMessage = null;

    public array $readinessBlockers = [];

    public bool $showReopenModal = false;

    public string $reopenReason = '';

    public bool $showOvertimeDecisionModal = false;

    public ?int $overtimeDecisionEmployeeId = null;

    public string $overtimeDecisionWorkDate = '';

    public string $overtimeCandidateKey = '';

    public string $overtimeDecision = '';

    public string $overtimeDecisionReason = '';

    public string $overtimeCandidateSummary = '';

    public bool $locked = false;

    public function mount(PayPeriod $payPeriod): void
    {
        $this->authorize('view', $payPeriod);
        Gate::authorize('marks.manage');

        $this->payPeriod = $payPeriod;
        $this->locked = $this->isBlocked();
    }

    public function render()
    {
        $records = $this->queryRawMarks();
        $summary = $this->summaryCounts();
        $employees = Employee::where('company_id', $this->payPeriod->company_id)
            ->orderBy('first_name')
            ->get();
        $faltas = $this->detectFaltas();
        $isBlocked = $this->isBlocked();
        $uploadedFiles = $this->payPeriod->uploadedFiles()->orderBy('created_at', 'desc')->get();
        $attendanceReviews = app(AttendanceReviewQuery::class)
            ->forPeriod($this->payPeriod, $this->uploaded_file_id);

        return view('livewire.nomina.revisar', [
            'records' => $records,
            'summary' => $summary,
            'employees' => $employees,
            'faltas' => $faltas,
            'isBlocked' => $isBlocked,
            'uploadedFiles' => $uploadedFiles,
            'overtimeReviews' => $attendanceReviews
                ->filter(fn ($review) => $review->analysis->overtimeCandidates->isNotEmpty()),
            'deficitReviews' => $attendanceReviews
                ->filter(fn ($review) => $review->analysis->deficits->isNotEmpty()),
        ]);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingUploadedFileId(): void
    {
        $this->resetPage();
    }

    public function openEditRawMark(int $id): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($id);

        if (! $rawMark) {
            return;
        }

        $this->authorize('edit', $rawMark);

        $this->editRawMarkId = $rawMark->id;
        $this->editEventAt = $rawMark->event_at->format('Y-m-d H:i:s');
        $this->editWarning = null;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editRawMarkId = null;
        $this->editEventAt = '';
        $this->editWarning = null;
        $this->resetErrorBag();
    }

    public function saveEditRawMark(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($this->editRawMarkId);

        if (! $rawMark) {
            return;
        }

        $this->authorize('edit', $rawMark);

        $validated = $this->validate([
            'editEventAt' => ['required', 'date'],
        ]);

        $newEventAt = Carbon::parse($validated['editEventAt']);
        $oldEventAt = $rawMark->event_at;
        $payPeriodStart = $this->payPeriod->start_date;
        $payPeriodEnd = $this->payPeriod->end_date;

        $isWithinPeriod = $newEventAt->betweenIncluded($payPeriodStart, $payPeriodEnd);
        $newStatus = $isWithinPeriod ? 'corrected' : 'out_of_period';
        $notes = $isWithinPeriod ? null : 'Editado fuera del período de nómina';

        $revisions = $rawMark->metadata['revisions'] ?? [];
        $revisions[] = [
            'action' => 'edit_event_at',
            'user_id' => Auth::id(),
            'old_event_at' => $oldEventAt->toDateTimeString(),
            'new_event_at' => $newEventAt->toDateTimeString(),
            'at' => now()->toDateTimeString(),
        ];

        $rawMark->update([
            'event_at' => $newEventAt,
            'status' => $newStatus,
            'notes' => $notes,
            'metadata' => array_merge($rawMark->metadata ?? [], ['revisions' => $revisions]),
        ]);

        $this->showEditModal = false;
        $this->editRawMarkId = null;
        $this->editEventAt = '';
    }

    public function openDeleteRawMark(int $id): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($id);

        if (! $rawMark) {
            return;
        }

        $this->authorize('delete', $rawMark);

        $this->deleteRawMarkId = $rawMark->id;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deleteRawMarkId = null;
    }

    public function deleteRawMark(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($this->deleteRawMarkId);

        if (! $rawMark) {
            return;
        }

        $this->authorize('delete', $rawMark);

        $revisions = $rawMark->metadata['revisions'] ?? [];
        $revisions[] = [
            'action' => 'delete',
            'user_id' => Auth::id(),
            'previous_status' => $rawMark->status,
            'at' => now()->toDateTimeString(),
        ];

        $rawMark->update([
            'status' => 'deleted',
            'metadata' => array_merge($rawMark->metadata ?? [], ['revisions' => $revisions]),
        ]);

        $this->showDeleteModal = false;
        $this->deleteRawMarkId = null;
    }

    public function openAssignModal(int $id): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($id);

        if (! $rawMark) {
            return;
        }

        $this->authorize('assign', $rawMark);

        $this->assignRawMarkId = $rawMark->id;
        $this->assignEmployeeId = $rawMark->employee_id;
        $this->assignApplyAll = false;
        $this->showAssignModal = true;
    }

    public function closeAssignModal(): void
    {
        $this->showAssignModal = false;
        $this->assignRawMarkId = null;
        $this->assignEmployeeId = null;
        $this->assignApplyAll = false;
        $this->resetErrorBag();
    }

    public function saveAssign(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($this->assignRawMarkId);

        if (! $rawMark) {
            return;
        }

        $this->authorize('assign', $rawMark);

        $validated = $this->validate([
            'assignEmployeeId' => ['required', 'integer', Rule::exists('employees', 'id')->where(function ($query) {
                $query->where('company_id', $this->payPeriod->company_id);
            })],
            'assignApplyAll' => ['boolean'],
        ]);

        $employeeId = (int) $validated['assignEmployeeId'];
        $applyAll = (bool) $validated['assignApplyAll'];

        $this->assignEmployeeToRawMark($rawMark, $employeeId);

        if ($applyAll) {
            $rawMarks = RawMark::withoutCompanyScope()
                ->where('company_id', $this->payPeriod->company_id)
                ->where('pay_period_id', $this->payPeriod->id)
                ->where('employee_external_id', $rawMark->employee_external_id)
                ->whereNull('employee_id')
                ->get();

            foreach ($rawMarks as $mark) {
                if ($mark->id === $rawMark->id) {
                    continue;
                }

                $this->assignEmployeeToRawMark($mark, $employeeId);
            }
        }

        $this->showAssignModal = false;
        $this->assignRawMarkId = null;
        $this->assignEmployeeId = null;
        $this->assignApplyAll = false;
    }

    public function markCorrected(int $id): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($id);

        if (! $rawMark) {
            return;
        }

        $this->authorize('manage', $rawMark);

        $revisions = $rawMark->metadata['revisions'] ?? [];
        $revisions[] = [
            'action' => 'mark_corrected',
            'user_id' => Auth::id(),
            'previous_status' => $rawMark->status,
            'at' => now()->toDateTimeString(),
        ];

        $rawMark->update([
            'status' => 'corrected',
            'metadata' => array_merge($rawMark->metadata ?? [], ['revisions' => $revisions]),
        ]);
    }

    public function openAbsencesModal(): void
    {
        $this->showAbsencesModal = true;
    }

    public function closeAbsencesModal(): void
    {
        $this->showAbsencesModal = false;
        $this->absenceEmployeeId = null;
        $this->absenceDate = '';
        $this->absenceReason = 'permission';
        $this->absenceNotes = null;
        $this->resetErrorBag();
    }

    public function justifyAbsence(int $employeeId, string $date, string $reason, ?string $notes = null): void
    {
        if ($this->isBlocked()) {
            return;
        }

        Gate::authorize('marks.manage');

        $this->validate([
            'absenceReason' => ['required', Rule::in(['holiday', 'permission', 'day_off', 'other'])],
        ]);

        $employee = Employee::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->find($employeeId);

        if (! $employee) {
            return;
        }

        $absence = JustifiedAbsence::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->where('pay_period_id', $this->payPeriod->id)
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->first();

        if ($absence) {
            $absence->update([
                'reason' => $reason,
                'notes' => $notes ?: null,
                'justified_by' => Auth::id(),
            ]);
        } else {
            JustifiedAbsence::withoutCompanyScope()->create([
                'company_id' => $this->payPeriod->company_id,
                'pay_period_id' => $this->payPeriod->id,
                'employee_id' => $employeeId,
                'date' => $date,
                'reason' => $reason,
                'notes' => $notes ?: null,
                'justified_by' => Auth::id(),
            ]);
        }

        $this->closeAbsencesModal();
    }

    public function saveDraft(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $this->payPeriod->update(['status' => 'validating']);

        session()->flash('success', 'Borrador guardado.');
    }

    public function openOvertimeDecision(
        int $employeeId,
        string $workDate,
        string $candidateKey,
        string $decision,
    ): void {
        if ($this->isBlocked()) {
            return;
        }

        $this->closeOvertimeDecisionModal();

        if (! in_array($decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)) {
            $this->addError('overtimeDecision', 'La decisión debe aprobar o rechazar el tramo completo.');

            return;
        }

        if (validator(['work_date' => $workDate], ['work_date' => ['required', 'date_format:Y-m-d']])->fails()) {
            $this->addError('overtimeCandidateKey', 'La fecha laboral del candidato no es válida.');

            return;
        }

        $employee = $this->findOvertimeEmployee($employeeId);

        if ($employee === null) {
            $this->addError('overtimeDecisionEmployeeId', 'El empleado no pertenece a este período.');

            return;
        }

        try {
            $review = app(PayrollShiftEvaluationResolver::class)
                ->review($this->payPeriod, $employee, $workDate);
        } catch (InvalidArgumentException) {
            $this->addError('overtimeCandidateKey', 'El candidato no pertenece a este período.');

            return;
        }
        $candidate = $review->analysis->overtimeCandidates->firstWhere('key', $candidateKey);

        if ($candidate === null) {
            $this->addError('overtimeCandidateKey', 'El candidato ya no coincide con las marcas vigentes.');

            return;
        }

        $this->overtimeDecisionEmployeeId = $employee->id;
        $this->overtimeDecisionWorkDate = $review->analysis->workDate->toDateString();
        $this->overtimeCandidateKey = $candidate->key;
        $this->overtimeDecision = $decision;
        $this->overtimeCandidateSummary = $candidate->start->format('H:i')
            .' → '.$candidate->end->format('H:i')
            .' · '.$candidate->minutes.' min';
        $this->showOvertimeDecisionModal = true;
    }

    public function closeOvertimeDecisionModal(): void
    {
        $this->showOvertimeDecisionModal = false;
        $this->overtimeDecisionEmployeeId = null;
        $this->overtimeDecisionWorkDate = '';
        $this->overtimeCandidateKey = '';
        $this->overtimeDecision = '';
        $this->overtimeDecisionReason = '';
        $this->overtimeCandidateSummary = '';
        $this->resetErrorBag();
    }

    public function saveOvertimeDecision(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $validated = $this->validate([
            'overtimeDecisionEmployeeId' => ['required', 'integer'],
            'overtimeDecisionWorkDate' => ['required', 'date_format:Y-m-d'],
            'overtimeCandidateKey' => ['required', 'string', 'size:64'],
            'overtimeDecision' => ['required', Rule::in([OvertimeDecision::APPROVED, OvertimeDecision::REJECTED])],
            'overtimeDecisionReason' => ['required', 'string', 'max:500'],
        ], [
            'overtimeDecisionReason.required' => 'Debe indicar el motivo de la decisión.',
        ]);
        $employee = $this->findOvertimeEmployee((int) $validated['overtimeDecisionEmployeeId']);

        if ($employee === null) {
            $this->addError('overtimeDecisionEmployeeId', 'El empleado no pertenece a este período.');

            return;
        }

        app(OvertimeDecisionRecorder::class)->decide(
            $this->payPeriod,
            $employee,
            $validated['overtimeDecisionWorkDate'],
            $validated['overtimeCandidateKey'],
            $validated['overtimeDecision'],
            $validated['overtimeDecisionReason'],
            Auth::user(),
        );

        $decision = $validated['overtimeDecision'] === OvertimeDecision::APPROVED ? 'aprobado' : 'rechazado';
        $this->closeOvertimeDecisionModal();
        $this->loadReadinessBlockers();

        session()->flash('success', "Tramo completo {$decision} y registrado en el historial.");
    }

    public function continueToReady(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        if ($this->loadReadinessBlockers()) {
            return;
        }

        $message = $this->readinessMessage();

        if ($message !== null) {
            $this->readyMessage = $message;
            $this->showReadyConfirm = true;

            return;
        }

        $this->moveToReady();
    }

    public function confirmContinueToReady(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $this->moveToReady();
    }

    public function cancelReadyConfirm(): void
    {
        $this->showReadyConfirm = false;
        $this->readyMessage = null;
    }

    public function openReopenModal(): void
    {
        if ($this->payPeriod->status !== 'processed') {
            return;
        }

        $this->authorize('manage', $this->payPeriod);
        $this->showReopenModal = true;
    }

    public function closeReopenModal(): void
    {
        $this->showReopenModal = false;
        $this->reopenReason = '';
        $this->resetErrorBag();
    }

    public function reopenProcessedPeriod(): void
    {
        if ($this->payPeriod->status !== 'processed') {
            return;
        }

        $this->authorize('manage', $this->payPeriod);
        $validated = $this->validate([
            'reopenReason' => ['required', 'string', 'max:500'],
        ]);

        $this->payPeriod = app(PayPeriodReopener::class)->reopen(
            $this->payPeriod,
            $validated['reopenReason'],
            Auth::user(),
        );
        $this->locked = false;
        $this->closeReopenModal();

        session()->flash('success', 'Período reabierto. Los resultados anteriores fueron invalidados.');
    }

    public function isBlocked(): bool
    {
        $status = PayPeriod::withoutCompanyScope()
            ->whereKey($this->payPeriod->id)
            ->value('status');

        return $status === null
            || in_array($status, ['processing', 'processed', 'approved', 'exported', 'cancelled'], true);
    }

    public function readinessBlockerLabel(string $code): string
    {
        return match ($code) {
            'pending_overtime_candidate' => 'Candidato de hora extra sin aprobar o rechazar',
            'ambiguous' => 'Más de dos marcas; no se puede determinar entrada y salida',
            'missing_pair' => 'Falta la marca de entrada o salida',
            'missing_assignment' => 'El empleado no tiene una jornada asignada',
            'missing_schedule' => 'La jornada asignada no define este día',
            default => 'La asistencia necesita revisión',
        };
    }

    public function statusClass(string $status): string
    {
        return match ($status) {
            'valid' => 'bg-green-100 text-green-800',
            'duplicate' => 'bg-yellow-100 text-yellow-800',
            'out_of_period' => 'bg-orange-100 text-orange-800',
            'unknown_employee' => 'bg-red-100 text-red-800',
            'corrected' => 'bg-blue-100 text-blue-800',
            'deleted' => 'bg-gray-100 text-gray-800',
            'justified' => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-400',
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'valid' => 'Válido',
            'duplicate' => 'Duplicado',
            'out_of_period' => 'Fuera de período',
            'unknown_employee' => 'Empleado desconocido',
            'corrected' => 'Corregido',
            'deleted' => 'Eliminado',
            'justified' => 'Justificado',
            default => 'Pendiente',
        };
    }

    private function queryRawMarks()
    {
        return RawMark::query()
            ->where('pay_period_id', $this->payPeriod->id)
            ->with(['employee', 'uploadedFile'])
            ->when($this->search, function ($query) {
                $search = '%'.$this->search.'%';
                $query->where(function ($q) use ($search) {
                    $q->where('employee_external_id', 'like', $search)
                        ->orWhereHas('employee', function ($sub) use ($search) {
                            $sub->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search);
                        });
                });
            })
            ->when($this->status, function ($query) {
                $query->where('status', $this->status);
            })
            ->when($this->uploaded_file_id, function ($query) {
                $query->where('uploaded_file_id', $this->uploaded_file_id);
            })
            ->orderBy('uploaded_file_id')
            ->orderBy('row_number')
            ->paginate(25);
    }

    private function summaryCounts(): array
    {
        $counts = RawMark::query()
            ->where('pay_period_id', $this->payPeriod->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $justified = JustifiedAbsence::withoutCompanyScope()
            ->where('pay_period_id', $this->payPeriod->id)
            ->count();

        return [
            'total' => array_sum($counts),
            'valid' => $counts['valid'] ?? 0,
            'duplicate' => $counts['duplicate'] ?? 0,
            'out_of_period' => $counts['out_of_period'] ?? 0,
            'unknown_employee' => $counts['unknown_employee'] ?? 0,
            'corrected' => $counts['corrected'] ?? 0,
            'deleted' => $counts['deleted'] ?? 0,
            'justified' => $justified,
        ];
    }

    private function detectFaltas(): Collection
    {
        $rules = new PayrollRules;
        $company = $this->payPeriod->company;
        $period = CarbonPeriod::create($this->payPeriod->start_date, $this->payPeriod->end_date);
        $employees = Employee::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->get();

        $marks = RawMark::withoutCompanyScope()
            ->where('pay_period_id', $this->payPeriod->id)
            ->whereNotIn('status', ['deleted', 'duplicate'])
            ->whereNotNull('employee_id')
            ->get(['employee_id', 'event_at']);

        $justified = JustifiedAbsence::withoutCompanyScope()
            ->where('pay_period_id', $this->payPeriod->id)
            ->get(['employee_id', 'date']);

        $faltas = collect();

        foreach ($employees as $employee) {
            foreach ($period as $date) {
                $day = CarbonImmutable::instance($date);

                if (! $rules->getWorkSchedule($company, $day->dayOfWeek)->is_working_day) {
                    continue;
                }

                if ($rules->isHoliday($company, $day)) {
                    continue;
                }

                $dateString = $day->toDateString();
                $hasMark = $marks->contains(function ($mark) use ($employee, $dateString) {
                    return $mark->employee_id === $employee->id && $mark->event_at->toDateString() === $dateString;
                });

                if ($hasMark) {
                    continue;
                }

                $justifiedAbsence = $justified->first(function ($absence) use ($employee, $dateString) {
                    return $absence->employee_id === $employee->id && $absence->date->toDateString() === $dateString;
                });

                $faltas->push([
                    'employee' => $employee,
                    'date' => $day,
                    'justified_absence' => $justifiedAbsence,
                ]);
            }
        }

        return $faltas;
    }

    private function findRawMark(?int $id): ?RawMark
    {
        if ($id === null) {
            return null;
        }

        return RawMark::find($id);
    }

    private function findOvertimeEmployee(?int $id): ?Employee
    {
        if ($id === null) {
            return null;
        }

        return Employee::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->find($id);
    }

    private function assignEmployeeToRawMark(RawMark $rawMark, int $employeeId): void
    {
        $revisions = $rawMark->metadata['revisions'] ?? [];
        $revisions[] = [
            'action' => 'assign_employee',
            'user_id' => Auth::id(),
            'previous_employee_id' => $rawMark->employee_id,
            'new_employee_id' => $employeeId,
            'at' => now()->toDateTimeString(),
        ];

        $newStatus = $rawMark->status === 'unknown_employee' ? 'corrected' : $rawMark->status;

        $rawMark->update([
            'employee_id' => $employeeId,
            'status' => $newStatus,
            'metadata' => array_merge($rawMark->metadata ?? [], ['revisions' => $revisions]),
        ]);
    }

    private function readinessMessage(): ?string
    {
        $invalidStatuses = RawMark::query()
            ->where('pay_period_id', $this->payPeriod->id)
            ->whereIn('status', ['pending', 'unknown_employee', 'out_of_period', 'duplicate'])
            ->exists();

        if ($invalidStatuses) {
            return 'Aún existen marcas pendientes, desconocidas, fuera de período o duplicadas. ¿Desea continuar de todas formas?';
        }

        return null;
    }

    private function moveToReady(): void
    {
        if ($this->loadReadinessBlockers()) {
            return;
        }

        $this->payPeriod->update(['status' => 'ready']);

        $this->showReadyConfirm = false;
        $this->readyMessage = null;

        session()->flash('success', 'Período listo para procesar.');
    }

    private function loadReadinessBlockers(): bool
    {
        $this->readinessBlockers = app(PayrollReadinessChecker::class)
            ->blockers($this->payPeriod)
            ->values()
            ->all();

        if ($this->readinessBlockers !== []) {
            $this->showReadyConfirm = false;
            $this->readyMessage = null;
        }

        return $this->readinessBlockers !== [];
    }
}
