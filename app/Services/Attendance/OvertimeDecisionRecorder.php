<?php

namespace App\Services\Attendance;

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\Payroll\BandSplit;
use App\Services\PayrollRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OvertimeDecisionRecorder
{
    public function __construct(
        private ShiftOccurrenceResolver $resolver,
        private AttendanceShiftAnalyzer $analyzer,
        private PayrollRules $rules,
    ) {}

    public function decide(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
        string $candidateKey,
        string $decision,
        string $reason,
        User $actor,
    ): OvertimeDecision {
        if (! in_array($decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)) {
            throw ValidationException::withMessages([
                'decision' => 'La decisión debe aprobar o rechazar el candidato completo.',
            ]);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'El motivo de la decisión es obligatorio.',
            ]);
        }

        $date = CarbonImmutable::parse($workDate)->startOfDay();

        return DB::transaction(function () use ($payPeriod, $employee, $date, $candidateKey, $decision, $reason, $actor): OvertimeDecision {
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

            $occurrence = $this->resolver->resolve($lockedEmployee, $date);
            $analysis = $this->analyzer->analyze(
                $occurrence,
                $this->rules->isHoliday($company, $date),
            );
            $candidate = $analysis->overtimeCandidates->firstWhere('key', $candidateKey);

            if ($candidate === null || $candidate->rateMinutes->totalMinutes() !== $candidate->minutes) {
                throw ValidationException::withMessages([
                    'candidate_key' => 'El candidato ya no coincide con las marcas y la jornada vigentes.',
                ]);
            }

            $previous = OvertimeDecision::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->where('pay_period_id', $lockedPeriod->id)
                ->where('employee_id', $lockedEmployee->id)
                ->whereDate('work_date', $date->toDateString())
                ->where('candidate_key', $candidate->key)
                ->current()
                ->lockForUpdate()
                ->first();

            if ($previous?->decision === $decision) {
                throw ValidationException::withMessages([
                    'decision' => 'El candidato ya tiene esa decisión vigente.',
                ]);
            }

            return OvertimeDecision::withoutCompanyScope()->create([
                'company_id' => $company->id,
                'pay_period_id' => $lockedPeriod->id,
                'employee_id' => $lockedEmployee->id,
                'work_date' => $date->toDateString(),
                'candidate_key' => $candidate->key,
                'fingerprint' => $candidate->fingerprint,
                'segment_kind' => $candidate->kind,
                'starts_at' => $candidate->start,
                'ends_at' => $candidate->end,
                'minutes' => $candidate->minutes,
                'rate_minutes' => $this->rateMinutes($candidate->rateMinutes),
                'decision' => $decision,
                'reason' => $reason,
                'decided_by' => $currentActor->id,
                'supersedes_id' => $previous?->id,
            ]);
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
                'pay_period' => 'El período, empleado o fecha laboral no admite decisiones.',
            ]);
        }
    }

    private function authorize(User $actor, Company $company): void
    {
        if (! $actor->is_active
            || ! $actor->can('marks.manage')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $company->id)) {
            throw new AuthorizationException('No está autorizado para decidir candidatos de esta empresa.');
        }
    }

    /** @return array{ordinary:int,extra25:int,extra50:int,extra75:int,extra100:int} */
    private function rateMinutes(BandSplit $rates): array
    {
        return [
            'ordinary' => $rates->ordinaryMinutes,
            'extra25' => $rates->extra25Minutes,
            'extra50' => $rates->extra50Minutes,
            'extra75' => $rates->extra75Minutes,
            'extra100' => $rates->extra100Minutes,
        ];
    }
}
