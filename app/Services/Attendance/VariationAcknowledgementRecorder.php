<?php

namespace App\Services\Attendance;

use App\Models\AttendanceVariationAcknowledgement;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\Payroll\LockedPayrollContext;
use App\Services\Payroll\PayrollContextLocker;
use App\Services\Payroll\PayrollContextTargets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

class VariationAcknowledgementRecorder
{
    public function __construct(
        private ShiftOccurrenceResolver $resolver,
        private AttendanceShiftAnalyzer $analyzer,
        private PayrollContextLocker $contextLocker,
    ) {}

    public function acknowledge(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
        string $variationKey,
        string $fingerprint,
        string $reason,
        User $actor,
    ): AttendanceVariationAcknowledgement {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'El motivo del reconocimiento es obligatorio.']);
        }
        $date = CarbonImmutable::parse($workDate)->startOfDay();

        return $this->contextLocker->within(
            $payPeriod->company_id,
            fn (): PayrollContextTargets => new PayrollContextTargets(
                payPeriodIds: [$payPeriod->id],
                employeeIds: [$employee->id],
                rawMarkIds: $this->resolver->resolve($employee, $date)->marks->pluck('id')->all(),
                holidayStart: $date,
            ),
            function (LockedPayrollContext $context) use ($payPeriod, $employee, $date, $variationKey, $fingerprint, $reason, $actor): AttendanceVariationAcknowledgement {
                $period = $context->payPeriod($payPeriod->id);
                $employee = $context->employee($employee->id);
                $currentActor = User::query()->findOrFail($actor->id);
                $activeCompanyId = current_company_id();

                if (
                    ! $currentActor->is_active
                    || ! $currentActor->can('marks.manage')
                    || ($activeCompanyId !== null && $activeCompanyId !== $context->company->id)
                    || (! $currentActor->hasRole('super_admin') && $currentActor->company_id !== $context->company->id)
                ) {
                    throw new AuthorizationException('No está autorizado para reconocer variaciones de esta empresa.');
                }
                $calendar = $context->holidayCalendar
                    ?? throw new LogicException('Variation acknowledgement requires holiday context.');
                $variation = $this->analyzer->analyze(
                    $this->resolver->resolve($employee, $date),
                    $calendar->isHoliday($date),
                    $calendar->generation($date),
                )->variations->firstWhere('key', $variationKey);
                if ($variation === null || ! hash_equals($variation->fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['variation_key' => 'La variación ya no está vigente.']);
                }

                return AttendanceVariationAcknowledgement::withoutCompanyScope()->create([
                    'record_version' => 2,
                    'company_id' => $context->company->id,
                    'pay_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'work_date' => $date->toDateString(),
                    'variation_key' => $variation->key,
                    'fingerprint' => $variation->fingerprint,
                    'variation_kind' => $variation->kind,
                    'entry_at' => $variation->entryAt,
                    'reason' => $reason,
                    'acknowledged_by' => $currentActor->id,
                ]);
            },
        );
    }
}
