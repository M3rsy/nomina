<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\RawMark;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\RawMarkMutationGuard;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class AuditedRawMarkRevision
{
    public function __construct(
        private PayrollContextLocker $contextLocker,
        private EmployeeScheduleAssigner $scheduleAssigner,
        private RawMarkMutationGuard $markMutationGuard,
    ) {}

    public function createEmployee(CreateEmployeeFromUnknownMarkCommand $command): CreatedUnknownEmployee
    {
        $input = $this->validateInput($command);
        $hiredAt = CarbonImmutable::parse($input['hired_at'])->startOfDay();
        $routingMark = RawMark::withoutCompanyScope()->find($command->rawMarkId);

        if ($routingMark === null) {
            throw ValidationException::withMessages([
                'raw_mark' => 'La marca ya no está disponible. Actualizá la revisión e intentá nuevamente.',
            ]);
        }

        $routingCompanyId = (int) $routingMark->company_id;
        $sourceExternalId = null;
        $sourcePayPeriodId = null;
        $sourceEventAt = null;
        $actor = User::query()->findOrFail($command->actorId);

        return $this->contextLocker->withinEmployeeCreation(
            $routingCompanyId,
            function () use ($command, $hiredAt, $routingCompanyId, &$sourceExternalId, &$sourcePayPeriodId, &$sourceEventAt): PayrollContextTargets {
                $source = RawMark::withoutCompanyScope()
                    ->where('company_id', $routingCompanyId)
                    ->whereKey($command->rawMarkId)
                    ->first();

                if ($source === null) {
                    throw ValidationException::withMessages([
                        'raw_mark' => 'La marca ya no está disponible para crear el empleado.',
                    ]);
                }

                $sourceExternalId = $source->employee_external_id;
                $sourcePayPeriodId = (int) $source->pay_period_id;
                $sourceEventAt = $source->event_at->toDateTimeString();
                $rawMarkIds = RawMark::withoutCompanyScope()
                    ->where('company_id', $source->company_id)
                    ->where('pay_period_id', $source->pay_period_id)
                    ->when(
                        $command->assignAll,
                        fn ($query) => $query
                            ->where('employee_external_id', $source->employee_external_id)
                            ->whereNull('employee_id')
                            ->where('status', 'unknown_employee'),
                        fn ($query) => $query->whereKey($source->id),
                    )
                    ->pluck('id')
                    ->push($source->id)
                    ->unique()
                    ->all();
                $payPeriodIds = PayPeriod::withoutCompanyScope()
                    ->withTrashed()
                    ->where('company_id', $source->company_id)
                    ->whereDate('end_date', '>=', $hiredAt->subDay()->toDateString())
                    ->pluck('id')
                    ->push($source->pay_period_id)
                    ->unique()
                    ->all();

                return new PayrollContextTargets(
                    payPeriodIds: $payPeriodIds,
                    profileIds: [$command->scheduleProfileId],
                    rawMarkIds: $rawMarkIds,
                );
            },
            function (LockedPayrollContext $context) use ($command, $input, $hiredAt, &$sourceExternalId, &$sourcePayPeriodId, &$sourceEventAt, $actor): array {
                if (! is_string($sourceExternalId)
                    || ! is_int($sourcePayPeriodId)
                    || ! is_string($sourceEventAt)) {
                    throw ValidationException::withMessages([
                        'raw_mark' => 'La identidad de la marca ya no está disponible. Actualizá la revisión e intentá nuevamente.',
                    ]);
                }

                $period = $context->payPeriod($sourcePayPeriodId);
                $profile = $context->profile($command->scheduleProfileId);

                $this->authorize($context, $actor);
                $this->validateCreationState(
                    $context,
                    $period,
                    $profile,
                    $sourceExternalId,
                    $sourceEventAt,
                    $hiredAt,
                    $input,
                );

                return [
                    'company_id' => $context->company->id,
                    'external_id' => $sourceExternalId,
                    'payment_code' => $input['payment_code'],
                    'first_name' => $input['first_name'],
                    'last_name' => $input['last_name'],
                    'dni' => $input['dni'],
                    'job_title' => $input['job_title'],
                    'hired_at' => $hiredAt->toDateString(),
                    'is_active' => true,
                ];
            },
            function (LockedPayrollContext $context, Employee $employee) use ($command, $input, $hiredAt, $actor): void {
                $this->scheduleAssigner->assignLocked(
                    $context,
                    $employee,
                    $context->profile($command->scheduleProfileId),
                    $hiredAt,
                    $input['reason'],
                    $actor,
                );
            },
            function (LockedPayrollContext $context, Employee $employee) use ($command, $input, $hiredAt, &$sourceExternalId, &$sourcePayPeriodId, &$sourceEventAt, $actor): CreatedUnknownEmployee {
                $source = $context->rawMark($command->rawMarkId);
                if (! is_string($sourceExternalId)
                    || ! is_int($sourcePayPeriodId)
                    || ! is_string($sourceEventAt)
                    || $source->employee_external_id !== $sourceExternalId
                    || (int) $source->pay_period_id !== $sourcePayPeriodId
                    || $source->event_at->toDateTimeString() !== $sourceEventAt) {
                    throw ValidationException::withMessages([
                        'raw_mark' => 'La marca cambió durante la operación. Actualizá la revisión e intentá nuevamente.',
                    ]);
                }

                $period = $context->payPeriod($source->pay_period_id);
                $this->validateLockedMarks($context, $source, $period, $hiredAt);

                $this->markMutationGuard->mutateLocked(
                    $context,
                    function (RawMark $mark) use ($employee, $actor, $input): void {
                        $metadata = is_array($mark->metadata) ? $mark->metadata : [];
                        $revisions = is_array($metadata['revisions'] ?? null) ? $metadata['revisions'] : [];
                        $revisions[] = [
                            'action' => 'create_and_assign_employee',
                            'user_id' => $actor->id,
                            'reason' => $input['reason'],
                            'new_employee_id' => $employee->id,
                            'at' => now()->toDateTimeString(),
                        ];
                        $metadata['revisions'] = $revisions;
                        $mark->update([
                            'employee_id' => $employee->id,
                            'status' => 'corrected',
                            'metadata' => $metadata,
                        ]);
                    },
                    $employee,
                );

                return new CreatedUnknownEmployee($employee->id, $context->rawMarks->count());
            },
        );
    }

    public function assignEmployee(AssignRawMarkEmployeeCommand $command): RawMarkRevisionResult
    {
        $targetEmployee = Employee::withoutCompanyScope()->withTrashed()->find($command->employeeId);

        if ($targetEmployee === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'El empleado ya no está disponible.',
            ]);
        }

        return $this->reviseMarks(
            $command->payPeriodId,
            $command->rawMarkId,
            $command->actorId,
            $command->reason,
            $targetEmployee,
            $command->assignAll,
            function (RawMark $mark, User $actor, string $reason) use ($targetEmployee): int {
                $metadata = is_array($mark->metadata) ? $mark->metadata : [];
                $revisions = is_array($metadata['revisions'] ?? null) ? $metadata['revisions'] : [];
                $revisions[] = [
                    'action' => 'assign_employee',
                    'user_id' => $actor->id,
                    'reason' => $reason,
                    'previous_employee_id' => $mark->employee_id,
                    'new_employee_id' => $targetEmployee->id,
                    'at' => now()->toDateTimeString(),
                ];
                $metadata['revisions'] = $revisions;
                $mark->update([
                    'employee_id' => $targetEmployee->id,
                    'status' => $mark->status === 'unknown_employee' ? 'corrected' : $mark->status,
                    'metadata' => $metadata,
                ]);

                return $mark->id;
            },
        );
    }

    public function markCorrected(MarkRawMarkCorrectedCommand $command): RawMarkRevisionResult
    {
        return $this->reviseMarks(
            $command->payPeriodId,
            $command->rawMarkId,
            $command->actorId,
            $command->reason,
            null,
            false,
            function (RawMark $mark, User $actor, string $reason): int {
                $metadata = is_array($mark->metadata) ? $mark->metadata : [];
                $revisions = is_array($metadata['revisions'] ?? null) ? $metadata['revisions'] : [];
                $revisions[] = [
                    'action' => 'mark_corrected',
                    'user_id' => $actor->id,
                    'reason' => $reason,
                    'previous_status' => $mark->status,
                    'new_status' => 'corrected',
                    'at' => now()->toDateTimeString(),
                ];
                $metadata['revisions'] = $revisions;
                $mark->update(['status' => 'corrected', 'metadata' => $metadata]);

                return $mark->id;
            },
        );
    }

    private function reviseMarks(
        int $payPeriodId,
        int $rawMarkId,
        int $actorId,
        string $reason,
        ?Employee $targetEmployee,
        bool $assignAll,
        Closure $mutation,
    ): RawMarkRevisionResult {
        $reason = $this->revisionReason($reason);
        $routingMark = RawMark::withoutCompanyScope()->find($rawMarkId);

        if ($routingMark === null) {
            $this->rejectUnavailableMark();
        }

        $companyId = (int) $routingMark->company_id;
        $actor = $this->revisionActor($actorId);
        $snapshots = [];
        $results = $this->markMutationGuard->mutateBatch(
            $companyId,
            function () use ($payPeriodId, $rawMarkId, $companyId, $actor, $targetEmployee, $assignAll, &$snapshots): array {
                $source = RawMark::withoutCompanyScope()
                    ->where('company_id', $companyId)
                    ->where('pay_period_id', $payPeriodId)
                    ->whereKey($rawMarkId)
                    ->first();

                if ($source === null) {
                    $this->rejectUnavailableMark();
                }

                $sourceSnapshot = $this->markSnapshot($source);
                $this->authorizeRevision($companyId, $actor);
                if ($targetEmployee !== null
                    && ($targetEmployee->trashed() || ! $targetEmployee->is_active || $targetEmployee->company_id !== $companyId)) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'El empleado ya no está disponible para esta empresa.',
                    ]);
                }
                $this->assertPeriodAcceptsRevision($companyId, $payPeriodId);
                $marks = RawMark::withoutCompanyScope()
                    ->where('company_id', $companyId)
                    ->where('pay_period_id', $payPeriodId)
                    ->when(
                        $assignAll,
                        fn ($query) => $query->where(fn ($matching) => $matching->whereKey($source->id)
                            ->orWhere(fn ($sameIdentity) => $sameIdentity
                                ->where('employee_external_id', $source->employee_external_id)
                                ->whereNull('employee_id'))),
                        fn ($query) => $query->whereKey($source->id),
                    )
                    ->orderBy('id')
                    ->get();
                $selectedSource = $marks->firstWhere('id', $source->id);
                if (! $selectedSource instanceof RawMark || $this->markSnapshot($selectedSource) !== $sourceSnapshot) {
                    $this->rejectChangedMark();
                }
                $snapshots = $marks->mapWithKeys(fn (RawMark $mark): array => [
                    $mark->id => $this->markSnapshot($mark),
                ])->all();

                return $marks->modelKeys();
            },
            function (RawMark $mark) use ($mutation, $actor, $reason, &$snapshots): mixed {
                $this->assertCurrentSnapshot($mark, $snapshots);

                return $mutation($mark, $actor, $reason);
            },
            targetEmployee: $targetEmployee,
        );

        return new RawMarkRevisionResult($results->count());
    }

    /** @return array{payment_code: string, first_name: string, last_name: string, dni: string, job_title: string, hired_at: string, reason: string} */
    private function validateInput(CreateEmployeeFromUnknownMarkCommand $command): array
    {
        return Validator::make([
            'payment_code' => trim($command->paymentCode),
            'first_name' => trim($command->firstName),
            'last_name' => trim($command->lastName),
            'dni' => trim($command->dni),
            'job_title' => trim($command->jobTitle),
            'hired_at' => $command->hiredAt,
            'reason' => trim($command->reason),
        ], [
            'payment_code' => ['required', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'dni' => ['required', 'string', 'max:32'],
            'job_title' => ['required', 'string', 'max:100'],
            'hired_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();
    }

    private function revisionReason(string $reason): string
    {
        return Validator::make(['reason' => trim($reason)], [
            'reason' => ['required', 'string', 'max:500'],
        ])->validate()['reason'];
    }

    private function authorizeRevision(int $companyId, User $actor): void
    {
        if (! $actor->is_active
            || ! $actor->can('marks.manage')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $companyId)) {
            throw new AuthorizationException('Not authorized to revise raw marks for this company.');
        }
    }

    private function revisionActor(int $actorId): User
    {
        return User::query()->find($actorId)
            ?? throw new AuthorizationException('The actor is no longer authorized to revise raw marks.');
    }

    private function assertPeriodAcceptsRevision(int $companyId, int $payPeriodId): void
    {
        $period = PayPeriod::withoutCompanyScope()->withTrashed()
            ->where('company_id', $companyId)
            ->find($payPeriodId);
        if ($period === null || $period->trashed()
            || in_array($period->status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true)
            || PayrollRun::withoutCompanyScope()
                ->where('company_id', $companyId)
                ->where('pay_period_id', $payPeriodId)
                ->whereIn('status', PayrollRun::ACTIVE_STATUSES)
                ->exists()) {
            throw ValidationException::withMessages([
                'raw_mark' => 'El período ya no permite modificar marcas.',
            ]);
        }
    }

    /** @return array{company_id:int,pay_period_id:int,employee_external_id:string,employee_id:?int,status:string,event_at:string} */
    private function markSnapshot(RawMark $mark): array
    {
        return [
            'company_id' => (int) $mark->company_id,
            'pay_period_id' => (int) $mark->pay_period_id,
            'employee_external_id' => $mark->employee_external_id,
            'employee_id' => $mark->employee_id === null ? null : (int) $mark->employee_id,
            'status' => $mark->status,
            'event_at' => $mark->event_at->toDateTimeString(),
        ];
    }

    /** @param array<int, array{company_id:int,pay_period_id:int,employee_external_id:string,employee_id:?int,status:string,event_at:string}> $snapshots */
    private function assertCurrentSnapshot(RawMark $mark, array $snapshots): void
    {
        if (($snapshots[$mark->id] ?? null) !== $this->markSnapshot($mark)) {
            $this->rejectChangedMark();
        }
    }

    private function rejectChangedMark(): never
    {
        throw ValidationException::withMessages([
            'raw_mark' => 'La marca cambió durante la operación. Actualizá la revisión e intentá nuevamente.',
        ]);
    }

    private function rejectUnavailableMark(): never
    {
        throw ValidationException::withMessages([
            'raw_mark' => 'La marca ya no está disponible. Actualizá la revisión e intentá nuevamente.',
        ]);
    }

    /** @param array{payment_code: string, first_name: string, last_name: string, dni: string, job_title: string, hired_at: string, reason: string} $input */
    private function validateCreationState(
        LockedPayrollContext $context,
        PayPeriod $period,
        WorkScheduleProfile $profile,
        string $sourceExternalId,
        string $sourceEventAt,
        CarbonImmutable $hiredAt,
        array $input,
    ): void {
        if ($period->trashed() || in_array($period->status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'raw_mark' => 'El período ya no permite crear y asignar empleados.',
            ]);
        }
        if ($profile->company_id !== $context->company->id || ! $profile->is_active || $profile->retired_at !== null) {
            throw ValidationException::withMessages([
                'schedule_profile_id' => 'La jornada seleccionada ya no está disponible.',
            ]);
        }
        if ($hiredAt->gt(CarbonImmutable::parse($sourceEventAt))) {
            throw ValidationException::withMessages([
                'hired_at' => 'La fecha de contratación debe ser anterior o igual a las marcas asignadas.',
            ]);
        }

        Validator::make([
            'external_id' => $sourceExternalId,
            'payment_code' => $input['payment_code'],
        ], [
            'external_id' => [
                'required', 'string', 'max:50',
                Rule::unique('employees', 'external_id')->where('company_id', $context->company->id),
            ],
            'payment_code' => [
                Rule::unique('employees', 'payment_code')->where('company_id', $context->company->id),
            ],
        ])->validate();
    }

    private function validateLockedMarks(
        LockedPayrollContext $context,
        RawMark $source,
        PayPeriod $period,
        CarbonImmutable $hiredAt,
    ): void {
        if ($source->employee_id !== null || $source->status !== 'unknown_employee') {
            throw ValidationException::withMessages([
                'raw_mark' => 'La marca fue modificada por otra operación. Actualizá la revisión e intentá nuevamente.',
            ]);
        }
        if ($context->rawMarks->contains(fn (RawMark $mark): bool => $mark->company_id !== $context->company->id
            || $mark->pay_period_id !== $period->id
            || $mark->employee_external_id !== $source->employee_external_id
            || $mark->employee_id !== null
            || $mark->status !== 'unknown_employee')) {
            throw ValidationException::withMessages([
                'raw_mark' => 'Las marcas cambiaron durante la operación. Actualizá la revisión e intentá nuevamente.',
            ]);
        }
        $firstMarkDate = $context->rawMarks->min(fn (RawMark $mark): string => $mark->event_at->toDateString());
        if ($firstMarkDate === null || $hiredAt->gt(CarbonImmutable::parse($firstMarkDate))) {
            throw ValidationException::withMessages([
                'hired_at' => 'La fecha de contratación debe ser anterior o igual a las marcas asignadas.',
            ]);
        }
    }

    private function authorize(LockedPayrollContext $context, User $actor): void
    {
        if (! $actor->is_active
            || ! $actor->can('employees.create')
            || ! $actor->can('marks.manage')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $context->company->id)) {
            throw new AuthorizationException('Not authorized to create an employee from this mark.');
        }
    }
}
