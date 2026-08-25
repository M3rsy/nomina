<?php

namespace App\Livewire\Nomina;

use App\Models\AttendanceException;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\OvertimeDecisionBatch;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\RawMark;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceExceptionRecorder;
use App\Services\Attendance\AttendanceReviewQuery;
use App\Services\Attendance\HolidayCalendarContext;
use App\Services\Attendance\ManualRawMarkRecorder;
use App\Services\Attendance\OvertimeDecisionBatchRequester;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\Attendance\PayrollPeriodReviewSnapshot;
use App\Services\Attendance\PayrollReadinessChecker;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\Attendance\RawMarkMutationGuard;
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\Attendance\VariationAcknowledgementRecorder;
use App\Services\Payroll\AssignRawMarkEmployeeCommand;
use App\Services\Payroll\AuditedRawMarkRevision;
use App\Services\Payroll\CreateEmployeeFromUnknownMarkCommand;
use App\Services\Payroll\MarkRawMarkCorrectedCommand;
use App\Services\Payroll\OvertimeReviewReader;
use App\Services\Payroll\PayPeriodReopener;
use App\Services\Payroll\PayrollReviewProjection;
use App\Services\Payroll\StartPayrollProcessing;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Revisar extends Component
{
    use WithPagination;

    private const MAX_OVERTIME_BATCH_TARGETS = 500;

    public PayPeriod $payPeriod;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public ?int $uploaded_file_id = null;

    #[Url]
    public string $overtimeStatus = 'pending';

    #[Url]
    public string $overtimeSearch = '';

    #[Url]
    public string $overtimeDate = '';

    #[Url]
    public string $overtimeRate = '';

    public bool $showEditModal = false;

    public ?int $editRawMarkId = null;

    public string $editEventAt = '';

    public string $editReason = '';

    public ?string $editWarning = null;

    public bool $showDeleteModal = false;

    public ?int $deleteRawMarkId = null;

    public string $deleteReason = '';

    public bool $showCorrectModal = false;

    public ?int $correctRawMarkId = null;

    public string $correctReason = '';

    public bool $showAssignModal = false;

    public ?int $assignRawMarkId = null;

    public ?int $assignEmployeeId = null;

    public bool $assignApplyAll = false;

    public string $assignReason = '';

    public bool $showCreateEmployeeModal = false;

    public ?int $createEmployeeRawMarkId = null;

    public string $createEmployeeExternalId = '';

    public string $createEmployeePaymentCode = '';

    public string $createEmployeeFirstName = '';

    public string $createEmployeeLastName = '';

    public string $createEmployeeDni = '';

    public string $createEmployeeJobTitle = '';

    public string $createEmployeeHiredAt = '';

    public ?int $createEmployeeScheduleProfileId = null;

    public string $createEmployeeReason = '';

    public bool $createEmployeeAssignAll = true;

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

    public string $overtimeApprovedStartsAt = '';

    public string $overtimeApprovedEndsAt = '';

    public array $selectedOvertimeCandidates = [];

    public bool $allFilteredOvertimeSelected = false;

    public bool $showOvertimeBatchModal = false;

    public string $overtimeBatchDecision = '';

    public string $overtimeBatchReason = '';

    public string $overtimeBatchRequestKey = '';

    public string $overtimeBatchSelection = '';

    public int $overtimeBatchCount = 0;

    public string $overtimeBatchFilterSummary = '';

    public ?int $activeOvertimeBatchId = null;

    #[Locked]
    public ?int $refreshedOvertimeBatchId = null;

    #[Locked]
    public ?int $activePayrollRunId = null;

    #[Locked]
    public string $payrollRunRequestKey = '';

    public bool $showAttendanceExceptionModal = false;

    public ?int $attendanceExceptionEmployeeId = null;

    public string $attendanceExceptionWorkDate = '';

    public string $attendanceDeficitKey = '';

    public string $attendanceExceptionDecision = '';

    public string $attendanceExceptionReason = '';

    public string $attendanceDeficitSummary = '';

    public bool $showManualMarkModal = false;

    public ?int $manualMarkEmployeeId = null;

    public string $manualMarkWorkDate = '';

    public string $manualMarkEventAt = '';

    public string $manualMarkReason = '';

    public string $variationReason = '';

    public bool $locked = false;

    private ?array $periodReviewSnapshot = null;

    public function mount(PayPeriod $payPeriod): void
    {
        $this->authorize('view', $payPeriod);
        Gate::authorize('marks.manage');

        if (! in_array($this->overtimeStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
            $this->overtimeStatus = 'pending';
        }
        if (! in_array($this->overtimeRate, ['', 'ordinary', 'extra25', 'extra50', 'extra75', 'extra100'], true)) {
            $this->overtimeRate = '';
        }
        if ($this->overtimeDate !== '') {
            $parts = explode('-', $this->overtimeDate);

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->overtimeDate)
                || count($parts) !== 3
                || ! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                $this->overtimeDate = '';
            }
        }

        $this->payPeriod = $payPeriod;
        $this->locked = $this->isBlocked();
        $this->recoverOvertimeBatch();
        $this->recoverPayrollRun();
    }

    public function render()
    {
        $records = $this->queryRawMarks();
        $employees = Employee::where('company_id', $this->payPeriod->company_id)
            ->orderBy('first_name')
            ->get();
        $scheduleProfiles = WorkScheduleProfile::where('company_id', $this->payPeriod->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $snapshot = $this->periodReviewSnapshot();
        $faltas = $this->detectFaltas($snapshot);
        $summary = $this->summaryCounts($faltas);
        $isBlocked = $this->isBlocked();
        $uploadedFiles = $this->payPeriod->uploadedFiles()->orderBy('created_at', 'desc')->get();
        $attendanceReviews = app(AttendanceReviewQuery::class)
            ->forPeriod($this->payPeriod, $this->uploaded_file_id, $snapshot);
        $overtimeRenderData = app(OvertimeReviewReader::class)->forPeriod(
            $this->payPeriod,
            $this->uploaded_file_id,
            [
                'search' => mb_strtolower(trim($this->overtimeSearch)),
                'status' => $this->overtimeStatus,
                'date' => $this->overtimeDate,
                'rate' => $this->overtimeRate,
            ],
            $this->getPage('overtimePage'),
        );
        $deficitReviews = $this->projectedReviewData()['deficits'] ?? $attendanceReviews
            ->filter(fn ($review) => $review->analysis->deficits->isNotEmpty());

        return view('livewire.nomina.revisar', [
            'records' => $records,
            'summary' => $summary,
            'employees' => $employees,
            'scheduleProfiles' => $scheduleProfiles,
            'faltas' => $faltas,
            'isBlocked' => $isBlocked,
            'uploadedFiles' => $uploadedFiles,
            'overtimeGroups' => $overtimeRenderData['groups'],
            'overtimeRows' => $overtimeRenderData['rows'],
            'pendingOvertimeMatchCount' => $overtimeRenderData['pendingCount'],
            'variationReviews' => $attendanceReviews
                ->filter(fn ($review) => $review->analysis->variations->isNotEmpty()),
            'deficitReviews' => $deficitReviews,
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
        $this->resetPage('overtimePage');
        $this->resetOvertimeSelection();
    }

    public function updatingOvertimeSearch(): void
    {
        $this->resetPage('overtimePage');
        $this->resetOvertimeSelection();
    }

    public function updatingOvertimeStatus(): void
    {
        $this->resetPage('overtimePage');
        $this->resetOvertimeSelection();
    }

    public function updatingOvertimeDate(): void
    {
        $this->resetPage('overtimePage');
        $this->resetOvertimeSelection();
    }

    public function updatingOvertimeRate(): void
    {
        $this->resetPage('overtimePage');
        $this->resetOvertimeSelection();
    }

    public function selectCurrentOvertimePage(): void
    {
        $this->allFilteredOvertimeSelected = false;
        $this->selectedOvertimeCandidates = $this->authoritativeOvertimeTargets(true)->keys()->all();
    }

    public function selectAllFilteredOvertime(): void
    {
        $this->allFilteredOvertimeSelected = true;
        $this->selectedOvertimeCandidates = [];
    }

    public function clearOvertimeSelection(): void
    {
        $this->resetOvertimeSelection();
    }

    public function openOvertimeBatch(string $decision): void
    {
        if ($this->isBlocked() || ! in_array($decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)) {
            return;
        }

        $targets = $this->resolvedOvertimeBatchTargets();
        if ($targets->count() > self::MAX_OVERTIME_BATCH_TARGETS) {
            $this->addError(
                'selectedOvertimeCandidates',
                'Hay más de 500 candidatos pendientes. Aplique filtros más específicos antes de continuar.',
            );

            return;
        }
        if ($targets->isEmpty()) {
            $this->addError('selectedOvertimeCandidates', 'Seleccione al menos un candidato pendiente.');

            return;
        }

        $this->resetErrorBag();
        $this->overtimeBatchDecision = $decision;
        $this->overtimeBatchSelection = $this->overtimeBatchConfirmation($targets);
        $this->overtimeBatchCount = $targets->count();
        $this->overtimeBatchFilterSummary = $this->overtimeFilterSummary();
        $this->overtimeBatchReason = '';
        $this->overtimeBatchRequestKey = (string) Str::uuid();
        $this->showOvertimeBatchModal = true;
    }

    public function closeOvertimeBatchModal(): void
    {
        $this->showOvertimeBatchModal = false;
        $this->overtimeBatchDecision = '';
        $this->overtimeBatchReason = '';
        $this->overtimeBatchRequestKey = '';
        $this->overtimeBatchSelection = '';
        $this->overtimeBatchCount = 0;
        $this->overtimeBatchFilterSummary = '';
        $this->resetErrorBag();
    }

    public function saveOvertimeBatch(): void
    {
        if ($this->isBlocked() || ! $this->showOvertimeBatchModal) {
            return;
        }

        $this->validate([
            'overtimeBatchDecision' => ['required', Rule::in([OvertimeDecision::APPROVED, OvertimeDecision::REJECTED])],
            'overtimeBatchReason' => ['required', 'string', 'max:500'],
            'overtimeBatchRequestKey' => ['required', 'uuid'],
        ], ['overtimeBatchReason.required' => 'Debe indicar un motivo común.']);
        $targets = $this->resolvedOvertimeBatchTargets();
        if ($targets->count() > self::MAX_OVERTIME_BATCH_TARGETS) {
            $this->addError(
                'selectedOvertimeCandidates',
                'Hay más de 500 candidatos pendientes. Aplique filtros más específicos antes de continuar.',
            );

            return;
        }
        if ($this->overtimeBatchConfirmation($targets) !== $this->overtimeBatchSelection) {
            $this->addError('selectedOvertimeCandidates', 'La selección cambió. Revísela antes de continuar.');

            return;
        }

        $batch = app(OvertimeDecisionBatchRequester::class)->request(
            $this->payPeriod, $targets->map(fn (array $target) => Arr::except($target, 'fingerprint'))->values()->all(), $this->overtimeBatchDecision,
            $this->overtimeBatchReason, Auth::user(), $this->overtimeBatchRequestKey,
        );
        $this->activeOvertimeBatchId = $batch->id;
        $this->refreshedOvertimeBatchId = null;
        $this->showOvertimeBatchModal = false;
        $this->resetOvertimeSelection();
        session()->flash('success', 'El lote fue enviado y se procesará en segundo plano.');
    }

    #[On('overtime-batch-terminal')]
    public function refreshAfterOvertimeBatch(int $batchId): void
    {
        if ($this->activeOvertimeBatchId !== $batchId || $this->refreshedOvertimeBatchId === $batchId) {
            return;
        }

        $batch = $this->actorOvertimeBatches()->find($batchId);
        if ($batch === null || ! in_array($batch->status, [
            OvertimeDecisionBatch::COMPLETED,
            OvertimeDecisionBatch::COMPLETED_WITH_ERRORS,
            'failed',
        ], true)) {
            return;
        }

        $this->refreshedOvertimeBatchId = $batchId;
        $this->periodReviewSnapshot = null;
        $this->resetPage('overtimePage');
    }

    #[On('overtime-batch-unavailable')]
    public function clearUnavailableOvertimeBatch(int $batchId): void
    {
        if ($this->activeOvertimeBatchId !== $batchId || $this->actorOvertimeBatches()->find($batchId) !== null) {
            return;
        }

        $this->activeOvertimeBatchId = null;
        $this->refreshedOvertimeBatchId = null;
    }

    #[On('payroll-run-terminal')]
    public function finishPayrollRun(int $runId): void
    {
        $this->authorize('view', $this->payPeriod);
        Gate::authorize('payroll.process');

        if ($this->activePayrollRunId !== $runId) {
            return;
        }

        $run = $this->payrollRuns()->find($runId);
        $period = PayPeriod::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->find($this->payPeriod->id);
        if ($run?->status !== PayrollRun::COMPLETED
            || $period === null
            || ! in_array($period->status, ['processed', 'approved', 'exported'], true)) {
            return;
        }

        $this->payPeriod = $period;
        $this->redirectRoute('nomina.procesar', ['payPeriod' => $period]);
    }

    #[On('payroll-run-retried')]
    public function syncRetriedPayrollRun(int $failedRunId, int $runId): void
    {
        $this->authorize('view', $this->payPeriod);
        Gate::authorize('payroll.process');

        if ($this->activePayrollRunId !== $failedRunId) {
            return;
        }

        $failedRun = $this->payrollRuns()->where('status', PayrollRun::FAILED)->find($failedRunId);
        $run = $this->payrollRuns()->find($runId);
        $latestRunId = $this->payrollRuns()->latest('id')->value('id');

        if ($failedRun === null
            || $run === null
            || $run->id <= $failedRun->id
            || $latestRunId !== $run->id) {
            return;
        }

        $this->activePayrollRunId = $run->id;
        $this->payrollRunRequestKey = $run->request_key;
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
        $this->editReason = '';
        $this->editWarning = null;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editRawMarkId = null;
        $this->editEventAt = '';
        $this->editReason = '';
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

        $this->editReason = trim($this->editReason);

        $validated = $this->validate([
            'editEventAt' => ['required', 'date'],
            'editReason' => ['required', 'string', 'max:500'],
        ]);

        $newEventAt = Carbon::parse($validated['editEventAt']);

        app(RawMarkMutationGuard::class)->mutate(
            $rawMark,
            function (RawMark $lockedMark) use ($newEventAt, $validated): void {
                $periodDate = CarbonImmutable::instance($newEventAt)->startOfDay();

                if ($lockedMark->employee_id !== null) {
                    $employee = Employee::withoutCompanyScope()
                        ->withTrashed()
                        ->find($lockedMark->employee_id);

                    if ($employee !== null) {
                        $periodDate = app(ShiftOccurrenceResolver::class)->workDateFor(
                            $employee,
                            $newEventAt,
                            $lockedMark->id,
                        );
                    }
                }

                $isWithinPeriod = PayPeriod::withoutCompanyScope()
                    ->where('company_id', $lockedMark->company_id)
                    ->whereDate('start_date', '<=', $periodDate->toDateString())
                    ->whereDate('end_date', '>=', $periodDate->toDateString())
                    ->exists();
                $revisions = $lockedMark->metadata['revisions'] ?? [];
                $revisions[] = [
                    'action' => 'edit_event_at',
                    'user_id' => Auth::id(),
                    'reason' => $validated['editReason'],
                    'old_event_at' => $lockedMark->event_at->toDateTimeString(),
                    'new_event_at' => $newEventAt->toDateTimeString(),
                    'at' => now()->toDateTimeString(),
                ];

                $lockedMark->update([
                    'event_at' => $newEventAt,
                    'status' => $isWithinPeriod ? 'corrected' : 'out_of_period',
                    'notes' => $isWithinPeriod ? null : 'Editado fuera del período de nómina',
                    'metadata' => array_merge($lockedMark->metadata ?? [], ['revisions' => $revisions]),
                ]);
            },
            targetEventAt: $newEventAt,
        );

        $this->showEditModal = false;
        $this->editRawMarkId = null;
        $this->editEventAt = '';
        $this->editReason = '';
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
        $this->deleteReason = '';
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deleteRawMarkId = null;
        $this->deleteReason = '';
        $this->resetErrorBag();
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

        $this->deleteReason = trim($this->deleteReason);
        $validated = $this->validate([
            'deleteReason' => ['required', 'string', 'max:500'],
        ]);

        app(RawMarkMutationGuard::class)->mutate($rawMark, function (RawMark $lockedMark) use ($validated): void {
            $revisions = $lockedMark->metadata['revisions'] ?? [];
            $revisions[] = [
                'action' => 'delete',
                'user_id' => Auth::id(),
                'reason' => $validated['deleteReason'],
                'previous_status' => $lockedMark->status,
                'new_status' => 'deleted',
                'at' => now()->toDateTimeString(),
            ];

            $lockedMark->update([
                'status' => 'deleted',
                'metadata' => array_merge($lockedMark->metadata ?? [], ['revisions' => $revisions]),
            ]);
        });

        $this->closeDeleteModal();
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
        $this->assignReason = '';
        $this->showAssignModal = true;
    }

    public function closeAssignModal(): void
    {
        $this->showAssignModal = false;
        $this->assignRawMarkId = null;
        $this->assignEmployeeId = null;
        $this->assignApplyAll = false;
        $this->assignReason = '';
        $this->resetErrorBag();
    }

    public function openCreateEmployeeModal(int $rawMarkId): void
    {
        if ($this->isBlocked()) {
            return;
        }

        Gate::authorize('create', Employee::class);
        $mark = $this->findRawMark($rawMarkId);
        if ($mark === null || $mark->employee_id !== null || $mark->status !== 'unknown_employee') {
            return;
        }

        $this->createEmployeeRawMarkId = $mark->id;
        $this->createEmployeeExternalId = $mark->employee_external_id;
        $this->createEmployeeHiredAt = max(
            $this->payPeriod->start_date->toDateString(),
            $mark->event_at->toDateString(),
        );
        $this->createEmployeeAssignAll = true;
        $this->showCreateEmployeeModal = true;
    }

    public function closeCreateEmployeeModal(): void
    {
        $this->reset([
            'showCreateEmployeeModal', 'createEmployeeRawMarkId', 'createEmployeeExternalId',
            'createEmployeePaymentCode', 'createEmployeeFirstName', 'createEmployeeLastName',
            'createEmployeeDni', 'createEmployeeJobTitle', 'createEmployeeHiredAt',
            'createEmployeeScheduleProfileId', 'createEmployeeReason',
        ]);
        $this->createEmployeeAssignAll = true;
        $this->resetErrorBag();
    }

    public function saveCreatedEmployee(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        Gate::authorize('create', Employee::class);
        $mark = $this->findRawMark($this->createEmployeeRawMarkId);
        if ($mark === null) {
            $this->addError('createEmployeeRawMarkId', 'La marca ya no está disponible. Actualizá la revisión e intentá nuevamente.');

            return;
        }

        $validated = $this->validate([
            'createEmployeePaymentCode' => ['required', 'string', 'max:50', Rule::unique('employees', 'payment_code')->where('company_id', $this->payPeriod->company_id)],
            'createEmployeeFirstName' => ['required', 'string', 'max:100'],
            'createEmployeeLastName' => ['required', 'string', 'max:100'],
            'createEmployeeDni' => ['required', 'string', 'max:32'],
            'createEmployeeJobTitle' => ['required', 'string', 'max:100'],
            'createEmployeeHiredAt' => ['required', 'date'],
            'createEmployeeScheduleProfileId' => ['required', 'integer', Rule::exists('work_schedule_profiles', 'id')->where('company_id', $this->payPeriod->company_id)],
            'createEmployeeReason' => ['required', 'string', 'max:500'],
            'createEmployeeAssignAll' => ['boolean'],
        ]);
        try {
            $result = app(AuditedRawMarkRevision::class)->createEmployee(new CreateEmployeeFromUnknownMarkCommand(
                rawMarkId: $mark->id,
                scheduleProfileId: (int) $validated['createEmployeeScheduleProfileId'],
                actorId: (int) Auth::id(),
                paymentCode: $validated['createEmployeePaymentCode'],
                firstName: $validated['createEmployeeFirstName'],
                lastName: $validated['createEmployeeLastName'],
                dni: $validated['createEmployeeDni'],
                jobTitle: $validated['createEmployeeJobTitle'],
                hiredAt: $validated['createEmployeeHiredAt'],
                reason: $validated['createEmployeeReason'],
                assignAll: (bool) $validated['createEmployeeAssignAll'],
            ));
        } catch (ValidationException $exception) {
            $fields = [
                'raw_mark' => 'createEmployeeRawMarkId',
                'external_id' => 'createEmployeeRawMarkId',
                'payment_code' => 'createEmployeePaymentCode',
                'schedule_profile_id' => 'createEmployeeScheduleProfileId',
                'hired_at' => 'createEmployeeHiredAt',
            ];
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($fields[$field] ?? 'createEmployeeRawMarkId', $message);
                }
            }

            return;
        }

        $this->closeCreateEmployeeModal();
        $this->periodReviewSnapshot = null;
        session()->flash('success', "Empleado creado y {$result->assignedMarks} marcas asignadas.");
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

        $this->assignReason = trim($this->assignReason);

        $validated = $this->validate([
            'assignEmployeeId' => ['required', 'integer', Rule::exists('employees', 'id')->where(function ($query) {
                $query->where('company_id', $this->payPeriod->company_id);
            })],
            'assignApplyAll' => ['boolean'],
            'assignReason' => ['required', 'string', 'max:500'],
        ]);

        try {
            app(AuditedRawMarkRevision::class)->assignEmployee(new AssignRawMarkEmployeeCommand(
                payPeriodId: $this->payPeriod->id,
                rawMarkId: $rawMark->id,
                employeeId: (int) $validated['assignEmployeeId'],
                actorId: (int) Auth::id(),
                reason: $validated['assignReason'],
                assignAll: (bool) $validated['assignApplyAll'],
            ));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field === 'employee_id' ? 'assignEmployeeId' : $field, $message);
                }
            }

            return;
        }

        $this->closeAssignModal();
    }

    public function openCorrectRawMark(int $id): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($id);

        if (! $rawMark) {
            return;
        }

        $this->authorize('manage', $rawMark);

        $this->correctRawMarkId = $rawMark->id;
        $this->correctReason = '';
        $this->showCorrectModal = true;
    }

    public function closeCorrectModal(): void
    {
        $this->showCorrectModal = false;
        $this->correctRawMarkId = null;
        $this->correctReason = '';
        $this->resetErrorBag();
    }

    public function markCorrected(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $rawMark = $this->findRawMark($this->correctRawMarkId);

        if (! $rawMark) {
            return;
        }

        $this->authorize('manage', $rawMark);

        $this->correctReason = trim($this->correctReason);
        $validated = $this->validate([
            'correctReason' => ['required', 'string', 'max:500'],
        ]);

        try {
            app(AuditedRawMarkRevision::class)->markCorrected(new MarkRawMarkCorrectedCommand(
                payPeriodId: $this->payPeriod->id,
                rawMarkId: $rawMark->id,
                actorId: (int) Auth::id(),
                reason: $validated['correctReason'],
            ));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->closeCorrectModal();
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
        $reason = $this->absenceReason;

        try {
            $payPeriod = PayPeriod::withoutCompanyScope()->whereKey($this->payPeriod->id)->first();

            if ($payPeriod === null || in_array($payPeriod->status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true)) {
                $this->locked = true;

                return;
            }

            $employee = Employee::withoutCompanyScope()
                ->where('company_id', $payPeriod->company_id)
                ->find($employeeId);

            if ($employee === null) {
                return;
            }

            $review = app(PayrollShiftEvaluationResolver::class)->review(
                $payPeriod,
                $employee,
                CarbonImmutable::parse($date)->startOfDay(),
            );
            $deficit = $review->analysis->deficits->firstWhere('kind', 'full_day_absence');

            if ($deficit === null) {
                $this->addError('absenceReason', 'La falta debe corresponder a una jornada programada completa y vigente.');

                return;
            }

            app(AttendanceExceptionRecorder::class)->decide(
                $payPeriod,
                $employee,
                $date,
                $deficit->key,
                AttendanceException::GRANTED,
                $notes ? sprintf('%s: %s', $reason, $notes) : $reason,
                Auth::user(),
            );
        } catch (ValidationException $exception) {
            if ($exception->errors()['pay_period'] ?? false) {
                return;
            }

            throw $exception;
        } catch (InvalidArgumentException) {
            return;
        }

        $this->closeAbsencesModal();
    }

    public function saveDraft(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $saved = DB::transaction(function (): bool {
            $payPeriod = $this->lockMutablePayPeriod();

            if ($payPeriod === null) {
                return false;
            }

            $payPeriod->update(['status' => 'validating']);
            $this->payPeriod = $payPeriod->refresh();

            return true;
        });

        if (! $saved) {
            return;
        }

        session()->flash('success', 'Borrador guardado.');
    }

    public function startPayrollRun(): void
    {
        $this->authorize('view', $this->payPeriod);
        Gate::authorize('payroll.process');
        $this->payrollRunRequestKey = (string) Str::uuid();
        $run = app(StartPayrollProcessing::class)->start(
            $this->payPeriod,
            Auth::user(),
            $this->payrollRunRequestKey,
        );
        $this->activePayrollRunId = $run->id;
        $this->payPeriod = $this->payPeriod->fresh();
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

        if (! in_array($decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED, OvertimeDecision::PARTIAL], true)) {
            $this->addError('overtimeDecision', 'La decisión debe aprobar o rechazar el tramo completo.');

            return;
        }

        if (validator(['work_date' => $workDate], ['work_date' => ['required', 'date_format:Y-m-d']])->fails()) {
            $this->addError('overtimeCandidateKey', 'La fecha laboral del candidato no es válida.');

            return;
        }

        $employee = $this->findPeriodEmployee($employeeId);

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
        if ($decision === OvertimeDecision::PARTIAL
            && $review->analysis->payrollPolicyKey !== 'duration-first-v2') {
            $this->addError('overtimeDecision', 'La aprobación parcial solo está disponible para candidatos de duración primero.');

            return;
        }

        $this->overtimeDecisionEmployeeId = $employee->id;
        $this->overtimeDecisionWorkDate = $review->analysis->workDate->toDateString();
        $this->overtimeCandidateKey = $candidate->key;
        $this->overtimeDecision = $decision;
        $this->overtimeCandidateSummary = $candidate->start->format('H:i')
            .' → '.$candidate->end->format('H:i')
            .' · '.$candidate->minutes.' min';
        $this->overtimeApprovedStartsAt = $candidate->start->format('Y-m-d\TH:i');
        $this->overtimeApprovedEndsAt = $candidate->end->format('Y-m-d\TH:i');
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
        $this->overtimeApprovedStartsAt = '';
        $this->overtimeApprovedEndsAt = '';
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
            'overtimeDecision' => ['required', Rule::in([OvertimeDecision::APPROVED, OvertimeDecision::REJECTED, OvertimeDecision::PARTIAL])],
            'overtimeDecisionReason' => ['required', 'string', 'max:500'],
            'overtimeApprovedStartsAt' => ['required_if:overtimeDecision,partial', 'date_format:Y-m-d\TH:i'],
            'overtimeApprovedEndsAt' => ['required_if:overtimeDecision,partial', 'date_format:Y-m-d\TH:i', 'after:overtimeApprovedStartsAt'],
        ], [
            'overtimeDecisionReason.required' => 'Debe indicar el motivo de la decisión.',
        ]);
        $employee = $this->findPeriodEmployee((int) $validated['overtimeDecisionEmployeeId']);

        if ($employee === null) {
            $this->addError('overtimeDecisionEmployeeId', 'El empleado no pertenece a este período.');

            return;
        }

        $recorder = app(OvertimeDecisionRecorder::class);
        if ($validated['overtimeDecision'] === OvertimeDecision::PARTIAL) {
            $recorder->approvePartial(
                $this->payPeriod, $employee, $validated['overtimeDecisionWorkDate'],
                $validated['overtimeCandidateKey'], $validated['overtimeApprovedStartsAt'],
                $validated['overtimeApprovedEndsAt'], $validated['overtimeDecisionReason'], Auth::user(),
            );
        } else {
            $recorder->decide(
                $this->payPeriod, $employee, $validated['overtimeDecisionWorkDate'],
                $validated['overtimeCandidateKey'], $validated['overtimeDecision'],
                $validated['overtimeDecisionReason'], Auth::user(),
            );
        }

        $message = $validated['overtimeDecision'] === OvertimeDecision::PARTIAL
            ? 'Tramo parcial aprobado y complemento rechazado.'
            : 'Tramo completo '.($validated['overtimeDecision'] === OvertimeDecision::APPROVED ? 'aprobado' : 'rechazado').' y registrado en el historial.';
        $this->closeOvertimeDecisionModal();
        $this->resetPage('overtimePage');
        $this->resetOvertimeSelection();
        $this->loadReadinessBlockers();

        session()->flash('success', $message);
    }

    #[On('overtime-decision-submitted')]
    public function saveOvertimeDecisionFromPanel(array $decision): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $this->overtimeDecisionEmployeeId = $decision['overtimeDecisionEmployeeId'] ?? null;
        $this->overtimeDecisionWorkDate = $decision['overtimeDecisionWorkDate'] ?? '';
        $this->overtimeCandidateKey = $decision['overtimeCandidateKey'] ?? '';
        $this->overtimeDecision = $decision['overtimeDecision'] ?? '';
        $this->overtimeDecisionReason = $decision['overtimeDecisionReason'] ?? '';
        $this->overtimeApprovedStartsAt = $decision['overtimeApprovedStartsAt'] ?? '';
        $this->overtimeApprovedEndsAt = $decision['overtimeApprovedEndsAt'] ?? '';
        $this->saveOvertimeDecision();
        $this->dispatch('overtime-decision-recorded')->to(OvertimeReviewPanel::class);
    }

    public function openAttendanceException(
        int $employeeId,
        string $workDate,
        string $deficitKey,
        string $decision,
    ): void {
        if ($this->isBlocked()) {
            return;
        }

        $this->closeAttendanceExceptionModal();

        if (! in_array($decision, [AttendanceException::GRANTED, AttendanceException::REJECTED, AttendanceException::REVOKED], true)) {
            $this->addError('attendanceExceptionDecision', 'La decisión debe conceder, rechazar o revocar la excepción completa.');

            return;
        }

        if (validator(['work_date' => $workDate], ['work_date' => ['required', 'date_format:Y-m-d']])->fails()) {
            $this->addError('attendanceDeficitKey', 'La fecha laboral del déficit no es válida.');

            return;
        }

        $employee = $this->findPeriodEmployee($employeeId);

        if ($employee === null) {
            $this->addError('attendanceExceptionEmployeeId', 'El empleado no pertenece a este período.');

            return;
        }

        try {
            $review = app(PayrollShiftEvaluationResolver::class)
                ->review($this->payPeriod, $employee, $workDate);
        } catch (InvalidArgumentException) {
            $this->addError('attendanceDeficitKey', 'El déficit no pertenece a este período.');

            return;
        }
        $deficit = $review->analysis->deficits->firstWhere('key', $deficitKey);

        if ($deficit === null) {
            $this->addError('attendanceDeficitKey', 'El déficit ya no coincide con las marcas vigentes.');

            return;
        }

        $currentException = $review->exceptionFor($deficit);

        if ($decision === AttendanceException::REVOKED
            && $currentException?->decision !== AttendanceException::GRANTED) {
            $this->addError('attendanceExceptionDecision', 'Solo puede revocar una excepción concedida.');

            return;
        }

        if ($deficit->kind === 'daily_shortfall'
            && $decision !== AttendanceException::REVOKED
            && $currentException !== null
            && $currentException->decision !== AttendanceException::REVOKED) {
            $this->addError('attendanceExceptionDecision', 'El déficit diario ya tiene una decisión vigente.');

            return;
        }

        $this->attendanceExceptionEmployeeId = $employee->id;
        $this->attendanceExceptionWorkDate = $review->analysis->workDate->toDateString();
        $this->attendanceDeficitKey = $deficit->key;
        $this->attendanceExceptionDecision = $decision;
        $this->attendanceDeficitSummary = $deficit->start === null
            ? 'Déficit diario · '.$deficit->minutes.' min'
            : $deficit->start->format('H:i').' → '.$deficit->end->format('H:i').' · '.$deficit->minutes.' min';
        $this->showAttendanceExceptionModal = true;
    }

    public function closeAttendanceExceptionModal(): void
    {
        $this->showAttendanceExceptionModal = false;
        $this->attendanceExceptionEmployeeId = null;
        $this->attendanceExceptionWorkDate = '';
        $this->attendanceDeficitKey = '';
        $this->attendanceExceptionDecision = '';
        $this->attendanceExceptionReason = '';
        $this->attendanceDeficitSummary = '';
        $this->resetErrorBag();
    }

    public function saveAttendanceException(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $validated = $this->validate([
            'attendanceExceptionEmployeeId' => ['required', 'integer'],
            'attendanceExceptionWorkDate' => ['required', 'date_format:Y-m-d'],
            'attendanceDeficitKey' => ['required', 'string', 'size:64'],
            'attendanceExceptionDecision' => ['required', Rule::in([AttendanceException::GRANTED, AttendanceException::REJECTED, AttendanceException::REVOKED])],
            'attendanceExceptionReason' => ['required', 'string', 'max:500'],
        ], [
            'attendanceExceptionReason.required' => 'Debe indicar el motivo de la excepción.',
        ]);
        $employee = $this->findPeriodEmployee((int) $validated['attendanceExceptionEmployeeId']);

        if ($employee === null) {
            $this->addError('attendanceExceptionEmployeeId', 'El empleado no pertenece a este período.');

            return;
        }

        app(AttendanceExceptionRecorder::class)->decide(
            $this->payPeriod,
            $employee,
            $validated['attendanceExceptionWorkDate'],
            $validated['attendanceDeficitKey'],
            $validated['attendanceExceptionDecision'],
            $validated['attendanceExceptionReason'],
            Auth::user(),
        );

        $decision = match ($validated['attendanceExceptionDecision']) {
            AttendanceException::GRANTED => 'concedida',
            AttendanceException::REJECTED => 'rechazada',
            default => 'revocada',
        };
        $this->periodReviewSnapshot = null;
        $this->closeAttendanceExceptionModal();

        session()->flash('success', "Excepción completa {$decision} y registrada en el historial.");
    }

    public function openManualMarkModal(?int $employeeId = null, ?string $workDate = null): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $this->closeManualMarkModal();
        $employee = $this->findPeriodEmployee($employeeId);

        if ($employeeId !== null && $employee === null) {
            $this->addError('manualMarkEmployeeId', 'El empleado no pertenece a este período.');

            return;
        }

        $workDate ??= $this->payPeriod->start_date->toDateString();
        $dateValidator = validator(['work_date' => $workDate], [
            'work_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$this->payPeriod->start_date->toDateString(),
                'before_or_equal:'.$this->payPeriod->end_date->toDateString(),
            ],
        ]);

        if ($dateValidator->fails()) {
            $this->addError('manualMarkWorkDate', 'La fecha laboral no pertenece a este período.');

            return;
        }

        if ($employee === null) {
            $this->addError('manualMarkEmployeeId', 'Debe seleccionar el caso incompleto que desea corregir.');

            return;
        }

        $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, $workDate);
        $observedMark = $occurrence->marks->first();

        if ($occurrence->status !== ShiftOccurrence::MISSING_PAIR
            || $occurrence->marks->count() !== 1
            || $observedMark?->source === RawMark::SOURCE_MANUAL) {
            $this->addError('manualMarkWorkDate', 'Solo puede completarse un par incompleto que ya contiene una marca observada.');

            return;
        }

        $this->manualMarkEmployeeId = $employee?->id;
        $this->manualMarkWorkDate = $workDate;
        $this->showManualMarkModal = true;
    }

    public function closeManualMarkModal(): void
    {
        $this->showManualMarkModal = false;
        $this->manualMarkEmployeeId = null;
        $this->manualMarkWorkDate = '';
        $this->manualMarkEventAt = '';
        $this->manualMarkReason = '';
        $this->resetErrorBag();
    }

    public function saveManualMark(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $validated = $this->validate([
            'manualMarkEmployeeId' => ['required', 'integer'],
            'manualMarkWorkDate' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$this->payPeriod->start_date->toDateString(),
                'before_or_equal:'.$this->payPeriod->end_date->toDateString(),
            ],
            'manualMarkEventAt' => ['required', 'date'],
            'manualMarkReason' => ['required', 'string', 'max:500'],
        ], [
            'manualMarkEmployeeId.required' => 'Debe seleccionar un empleado.',
            'manualMarkWorkDate.required' => 'Debe indicar la fecha laboral.',
            'manualMarkEventAt.required' => 'Debe indicar la fecha y hora real de la marca.',
            'manualMarkReason.required' => 'Debe indicar por qué falta la marca del reloj.',
        ]);
        $employee = $this->findPeriodEmployee((int) $validated['manualMarkEmployeeId']);

        if ($employee === null) {
            $this->addError('manualMarkEmployeeId', 'El empleado no pertenece a este período.');

            return;
        }

        app(ManualRawMarkRecorder::class)->record(
            $this->payPeriod,
            $employee,
            $validated['manualMarkWorkDate'],
            $validated['manualMarkEventAt'],
            $validated['manualMarkReason'],
            Auth::user(),
        );

        $this->closeManualMarkModal();
        $this->loadReadinessBlockers();
        $this->resetPage();

        session()->flash('success', 'Marca manual auditada y registrada sin modificar el archivo de origen.');
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

        $this->startProcessing();
    }

    public function acknowledgeVariation(
        int $employeeId,
        string $workDate,
        string $variationKey,
        string $fingerprint,
    ): void {
        if ($this->isBlocked()) {
            return;
        }

        $validated = $this->validate([
            'variationReason' => ['required', 'string', 'max:500'],
        ], [
            'variationReason.required' => 'Debe indicar el motivo del reconocimiento.',
        ]);
        $employee = $this->findPeriodEmployee($employeeId);
        if ($employee === null) {
            $this->addError('variation', 'El empleado no pertenece a este período.');

            return;
        }

        app(VariationAcknowledgementRecorder::class)->acknowledge(
            $this->payPeriod,
            $employee,
            $workDate,
            $variationKey,
            $fingerprint,
            $validated['variationReason'],
            Auth::user(),
        );
        $this->variationReason = '';
        $this->periodReviewSnapshot = null;

        session()->flash('success', 'Variación reconocida y registrada en el historial.');
    }

    public function confirmContinueToReady(): void
    {
        if ($this->isBlocked()) {
            return;
        }

        $this->startProcessing();
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
            || in_array($status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true)
            || $this->payrollRuns()
                ->whereIn('status', PayrollRun::ACTIVE_STATUSES)
                ->exists();
    }

    public function readinessBlockerLabel(string $code): string
    {
        return match ($code) {
            'pending_overtime_candidate' => 'Candidato de hora extra sin aprobar o rechazar',
            'ambiguous' => 'Más de dos marcas; no se puede determinar entrada y salida',
            'missing_pair' => 'Falta la marca de entrada o salida',
            'missing_assignment' => 'El empleado no tiene una jornada asignada',
            'missing_schedule' => 'La jornada asignada no define este día',
            'invalid_rate_bands' => 'Las bandas salariales no cubren las 24 horas correctamente',
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
            ->orderBy('event_at')
            ->orderBy('id')
            ->paginate(25);
    }

    private function summaryCounts(Collection $faltas): array
    {
        $counts = RawMark::query()
            ->where('pay_period_id', $this->payPeriod->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'valid' => $counts['valid'] ?? 0,
            'duplicate' => $counts['duplicate'] ?? 0,
            'out_of_period' => $counts['out_of_period'] ?? 0,
            'unknown_employee' => $counts['unknown_employee'] ?? 0,
            'corrected' => $counts['corrected'] ?? 0,
            'deleted' => $counts['deleted'] ?? 0,
            'justified' => $faltas->whereNotNull('attendance_exception')->count(),
        ];
    }

    private function detectFaltas(?array $snapshot = null): Collection
    {
        return ($snapshot ?? $this->periodReviewSnapshot())['absences'];
    }

    private function findRawMark(?int $id): ?RawMark
    {
        if ($id === null) {
            return null;
        }

        return RawMark::find($id);
    }

    private function findPeriodEmployee(?int $id): ?Employee
    {
        if ($id === null) {
            return null;
        }

        return Employee::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->find($id);
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

    private function startProcessing(): void
    {
        $this->payrollRunRequestKey = $this->payrollRunRequestKey !== ''
            ? $this->payrollRunRequestKey
            : (string) Str::uuid();
        $run = app(StartPayrollProcessing::class)->start(
            $this->payPeriod,
            Auth::user(),
            $this->payrollRunRequestKey,
        );
        $this->activePayrollRunId = $run->id;
        $this->payPeriod = $this->payPeriod->fresh();

        $this->showReadyConfirm = false;
        $this->readyMessage = null;

        session()->flash('success', 'La nómina quedó en cola para procesarse.');
    }

    private function loadReadinessBlockers(
        ?PayPeriod $payPeriod = null,
        ?HolidayCalendarContext $calendarContext = null,
    ): bool {
        $target = $payPeriod ?? $this->payPeriod;
        $this->periodReviewSnapshot = app(PayrollPeriodReviewSnapshot::class)
            ->forPeriod($target, $calendarContext);
        $this->readinessBlockers = app(PayrollReadinessChecker::class)
            ->blockers($target, $calendarContext, $this->periodReviewSnapshot)
            ->values()
            ->all();

        if ($this->readinessBlockers !== []) {
            $this->showReadyConfirm = false;
            $this->readyMessage = null;
        }

        return $this->readinessBlockers !== [];
    }

    private function periodReviewSnapshot(): array
    {
        return $this->periodReviewSnapshot ??= app(PayrollPeriodReviewSnapshot::class)
            ->forPeriod($this->payPeriod, includeBlockers: false);
    }

    private function authoritativeSelectedOvertimeTargets(): Collection
    {
        $selected = collect($this->selectedOvertimeCandidates)
            ->filter(fn (mixed $token): bool => is_string($token)
                && preg_match('/^\d+\|\d{4}-\d{2}-\d{2}\|[a-f0-9]{64}$/D', $token) === 1)
            ->flip()->all();

        return $this->authoritativeOvertimeTargets()
            ->filter(fn (array $target, string $token): bool => isset($selected[$token]));
    }

    private function resolvedOvertimeBatchTargets(): Collection
    {
        return $this->allFilteredOvertimeSelected
            ? $this->authoritativeOvertimeTargets()
            : $this->authoritativeSelectedOvertimeTargets();
    }

    private function authoritativeOvertimeTargets(bool $currentPage = false): Collection
    {
        $rows = $this->filteredOvertimeRows(
            app(AttendanceReviewQuery::class)
                ->forPeriod($this->payPeriod, $this->uploaded_file_id, $this->periodReviewSnapshot()),
        );
        if ($currentPage) {
            $rows = $rows->forPage($this->getPage('overtimePage'), 25)->values();
        }

        return $rows->filter(fn (array $row): bool => $row['decision'] === null)
            ->mapWithKeys(function (array $row): array {
                $target = [
                    'employee_id' => $row['review']->employee->id,
                    'work_date' => $row['review']->analysis->workDate->toDateString(),
                    'candidate_key' => $row['candidate']->key,
                    'fingerprint' => $row['candidate']->fingerprint,
                ];

                return [implode('|', Arr::except($target, 'fingerprint')) => $target];
            });
    }

    private function filteredOvertimeRows(Collection $attendanceReviews): Collection
    {
        $search = mb_strtolower(trim($this->overtimeSearch));

        return $attendanceReviews
            ->flatMap(fn ($review) => $review->analysis->overtimeCandidates->map(fn ($candidate) => [
                'review' => $review,
                'candidate' => $candidate,
                'decision' => $review->decisionFor($candidate),
            ]))
            ->filter(function (array $row) use ($search): bool {
                $review = $row['review'];
                $candidate = $row['candidate'];
                $employee = mb_strtolower($review->employee->full_name.' '.$review->employee->external_id);
                $rateMinutes = match ($this->overtimeRate) {
                    'ordinary' => $candidate->rateMinutes->ordinaryMinutes,
                    'extra25' => $candidate->rateMinutes->extra25Minutes,
                    'extra50' => $candidate->rateMinutes->extra50Minutes,
                    'extra75' => $candidate->rateMinutes->extra75Minutes,
                    'extra100' => $candidate->rateMinutes->extra100Minutes,
                    default => 1,
                };

                return ($this->overtimeStatus === 'all'
                        || ($row['decision']?->decision ?? 'pending') === $this->overtimeStatus)
                    && ($search === '' || str_contains($employee, $search))
                    && ($this->overtimeDate === ''
                        || $review->analysis->workDate->toDateString() === $this->overtimeDate)
                    && $rateMinutes > 0;
            })
            ->values();
    }

    private function projectedReviewData(): ?array
    {
        if ($this->uploaded_file_id !== null) {
            return null;
        }

        $projection = app(PayrollReviewProjection::class);
        $generation = $projection->freshGeneration($this->payPeriod);

        if ($generation === null) {
            return null;
        }

        return [
            'deficits' => $projection->deficitReviews($this->payPeriod, $generation),
        ];
    }

    private function recoverOvertimeBatch(): void
    {
        $batch = $this->actorOvertimeBatches()
            ->whereIn('status', [OvertimeDecisionBatch::QUEUED, OvertimeDecisionBatch::PROCESSING])
            ->latest('id')->first();
        if ($batch !== null) {
            $this->activeOvertimeBatchId = $batch->id;
        }
    }

    private function recoverPayrollRun(): void
    {
        $this->activePayrollRunId = $this->payrollRuns()
            ->whereIn('status', PayrollRun::ACTIVE_STATUSES)
            ->latest('id')
            ->value('id')
            ?? $this->payrollRuns()
                ->where('status', PayrollRun::FAILED)
                ->latest('id')
                ->value('id');
    }

    private function payrollRuns()
    {
        return PayrollRun::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->where('pay_period_id', $this->payPeriod->id);
    }

    private function actorOvertimeBatches()
    {
        return OvertimeDecisionBatch::withoutCompanyScope()
            ->where('company_id', $this->payPeriod->company_id)
            ->where('pay_period_id', $this->payPeriod->id)
            ->where('requested_by', Auth::id());
    }

    private function overtimeBatchConfirmation(Collection $targets): string
    {
        $candidateSnapshot = $targets->map(
            fn (array $target, string $token): string => $token.'|'.$target['fingerprint']
        )->sort()->values()->all();

        return hash('sha256', json_encode([
            'filters' => [
                'search' => trim($this->overtimeSearch),
                'status' => $this->overtimeStatus,
                'date' => $this->overtimeDate,
                'rate' => $this->overtimeRate,
                'uploaded_file_id' => $this->uploaded_file_id,
                'all' => $this->allFilteredOvertimeSelected,
            ],
            'candidates' => $candidateSnapshot,
        ], JSON_THROW_ON_ERROR));
    }

    private function overtimeFilterSummary(): string
    {
        $parts = ['Estado: Pendientes'];
        if (($search = trim($this->overtimeSearch)) !== '') {
            $parts[] = 'Empleado: '.$search;
        }
        if ($this->overtimeDate !== '') {
            $parts[] = 'Fecha: '.$this->overtimeDate;
        }
        if ($this->overtimeRate !== '') {
            $parts[] = 'Porcentaje: '.match ($this->overtimeRate) {
                'ordinary' => 'Ordinario', 'extra25' => '25%', 'extra50' => '50%',
                'extra75' => '75%', 'extra100' => '100%', default => $this->overtimeRate,
            };
        }
        if ($this->uploaded_file_id !== null) {
            $file = $this->payPeriod->uploadedFiles()->whereKey($this->uploaded_file_id)->value('original_name');
            if ($file !== null) {
                $parts[] = 'Archivo: '.$file;
            }
        }

        return implode(' · ', $parts);
    }

    private function resetOvertimeSelection(): void
    {
        $this->selectedOvertimeCandidates = [];
        $this->allFilteredOvertimeSelected = false;
        $this->closeOvertimeBatchModal();
    }

    private function lockMutablePayPeriod(): ?PayPeriod
    {
        $payPeriod = PayPeriod::withoutCompanyScope()
            ->whereKey($this->payPeriod->id)
            ->lockForUpdate()
            ->first();

        if ($payPeriod === null || in_array($payPeriod->status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true)) {
            $this->locked = true;

            return null;
        }

        return $payPeriod;
    }
}
