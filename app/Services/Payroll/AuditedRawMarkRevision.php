<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\RawMarkMutationGuard;
use Carbon\CarbonImmutable;
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
