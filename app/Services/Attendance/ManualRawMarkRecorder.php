<?php

namespace App\Services\Attendance;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualRawMarkRecorder
{
    public function __construct(private ShiftOccurrenceResolver $resolver) {}

    public function record(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
        CarbonInterface|string $eventAt,
        string $reason,
        User $actor,
    ): RawMark {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo es obligatorio y no puede superar 500 caracteres.',
            ]);
        }

        $date = CarbonImmutable::parse($workDate)->startOfDay();
        $timestamp = CarbonImmutable::parse($eventAt);

        return DB::transaction(function () use ($payPeriod, $employee, $date, $timestamp, $reason, $actor): RawMark {
            $lockedPeriod = PayPeriod::withoutCompanyScope()
                ->withTrashed()
                ->whereKey($payPeriod->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedEmployee = Employee::withoutCompanyScope()
                ->withTrashed()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentActor = User::query()->findOrFail($actor->id);
            $company = Company::query()->findOrFail($lockedPeriod->company_id);

            $this->validateContext($lockedPeriod, $lockedEmployee, $date);
            $this->authorize($currentActor, $company);

            $before = $this->resolver->resolve($lockedEmployee, $date);

            $observedMark = $before->marks->first();

            if ($before->status !== ShiftOccurrence::MISSING_PAIR
                || $before->marks->count() !== 1
                || $observedMark?->source === RawMark::SOURCE_MANUAL) {
                throw ValidationException::withMessages([
                    'work_date' => 'Solo puede completarse un par incompleto que ya contiene una marca observada.',
                ]);
            }

            if ($before->marks->contains(fn (RawMark $mark) => $mark->event_at->equalTo($timestamp))) {
                throw ValidationException::withMessages([
                    'event_at' => 'Ya existe una marca a esa fecha y hora.',
                ]);
            }

            $createdAt = now();
            $mark = RawMark::withoutCompanyScope()->create([
                'company_id' => $company->id,
                'pay_period_id' => $lockedPeriod->id,
                'uploaded_file_id' => null,
                'employee_external_id' => $lockedEmployee->external_id,
                'employee_id' => $lockedEmployee->id,
                'event_at' => $timestamp,
                'raw_line' => null,
                'source' => RawMark::SOURCE_MANUAL,
                'row_number' => null,
                'status' => 'corrected',
                'notes' => 'Marca manual auditada',
                'metadata' => [
                    'revisions' => [[
                        'action' => 'manual_create',
                        'user_id' => $currentActor->id,
                        'work_date' => $date->toDateString(),
                        'event_at' => $timestamp->toDateTimeString(),
                        'reason' => $reason,
                        'at' => $createdAt->toDateTimeString(),
                    ]],
                ],
            ]);

            $after = $this->resolver->resolve($lockedEmployee, $date);
            if ($after->status !== ShiftOccurrence::RESOLVED || ! $after->marks->contains('id', $mark->id)) {
                throw ValidationException::withMessages([
                    'event_at' => 'La fecha y hora no pertenecen a la jornada laboral seleccionada.',
                ]);
            }

            return $mark;
        });
    }

    private function validateContext(PayPeriod $payPeriod, Employee $employee, CarbonImmutable $date): void
    {
        if ($payPeriod->trashed()
            || $employee->trashed()
            || $employee->company_id !== $payPeriod->company_id
            || $date->lt($payPeriod->start_date->startOfDay())
            || $date->gt($payPeriod->end_date)
            || in_array($payPeriod->status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'pay_period' => 'El período, empleado o fecha laboral no admite marcas manuales.',
            ]);
        }
    }

    private function authorize(User $actor, Company $company): void
    {
        if (! $actor->is_active
            || ! $actor->can('marks.manage')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $company->id)) {
            throw new AuthorizationException('No está autorizado para registrar marcas manuales de esta empresa.');
        }
    }
}
