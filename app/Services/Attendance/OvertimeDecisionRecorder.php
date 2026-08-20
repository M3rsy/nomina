<?php

namespace App\Services\Attendance;

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\OvertimeDecisionBatchItem;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\Payroll\BandSplit;
use App\Services\Payroll\BandSplitter;
use App\Services\Payroll\LockedPayrollContext;
use App\Services\Payroll\PayrollContextLocker;
use App\Services\Payroll\PayrollContextTargets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

class OvertimeDecisionRecorder
{
    private const DURATION_FIRST_OVERTIME_BANDS = [
        ['start' => 0, 'end' => 360, 'bucket' => 'extra75'],
        ['start' => 360, 'end' => 1080, 'bucket' => 'extra25'],
        ['start' => 1080, 'end' => 1440, 'bucket' => 'extra50'],
    ];

    public function __construct(
        private ShiftOccurrenceResolver $resolver,
        private AttendanceShiftAnalysisResolver $analysisResolver,
        private PayrollContextLocker $contextLocker,
        private BandSplitter $bandSplitter,
        private AttendanceDecisionMatcher $decisionMatcher,
        private AttendanceDecisionAppender $decisionAppender,
    ) {}

    public function approvePartial(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
        string $candidateKey,
        CarbonInterface|string $approvedStartsAt,
        CarbonInterface|string $approvedEndsAt,
        string $reason,
        User $actor,
    ): OvertimeDecision {
        return $this->decide(
            $payPeriod, $employee, $workDate, $candidateKey, OvertimeDecision::APPROVED,
            $reason, $actor, approvedStartsAt: $approvedStartsAt, approvedEndsAt: $approvedEndsAt,
        );
    }

    public function decide(
        PayPeriod $payPeriod,
        Employee $employee,
        CarbonInterface|string $workDate,
        string $candidateKey,
        string $decision,
        string $reason,
        User $actor,
        ?int $batchItemId = null,
        CarbonInterface|string|null $approvedStartsAt = null,
        CarbonInterface|string|null $approvedEndsAt = null,
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
        $batchItem = $batchItemId === null ? null : OvertimeDecisionBatchItem::query()
            ->with('batch')->findOrFail($batchItemId);
        if ($batchItem !== null) {
            $batch = $batchItem->batch;
            if ($batch->company_id !== $payPeriod->company_id || $batch->pay_period_id !== $payPeriod->id
                || $batch->requested_by !== $actor->id || $batch->decision !== $decision || $batch->reason !== $reason
                || $batchItem->employee_id !== $employee->id || ! $batchItem->work_date->isSameDay($date)
                || $batchItem->candidate_key !== $candidateKey) {
                throw new LogicException('Batch item identity does not match the decision context.');
            }
            if ($linked = OvertimeDecision::withoutCompanyScope()->where('batch_item_id', $batchItem->id)->first()) {
                return $this->verifiedLinkedDecision($linked, $batchItem, $payPeriod, $employee, $date, $candidateKey, $decision, $reason, $actor);
            }
        }

        return $this->contextLocker->within(
            $payPeriod->company_id,
            fn (): PayrollContextTargets => new PayrollContextTargets(
                payPeriodIds: [$payPeriod->id],
                employeeIds: [$employee->id],
                rawMarkIds: $this->resolver->resolve($employee, $date)->marks->pluck('id')->all(),
                holidayStart: $date,
            ),
            function (LockedPayrollContext $context) use ($payPeriod, $employee, $date, $candidateKey, $decision, $reason, $actor, $batchItem, $approvedStartsAt, $approvedEndsAt): OvertimeDecision {
                $lockedPeriod = $context->payPeriod($payPeriod->id);
                $lockedEmployee = $context->employee($employee->id);
                $calendarContext = $context->holidayCalendar
                    ?? throw new LogicException('Overtime authorization requires holiday context.');
                $currentActor = User::query()->findOrFail($actor->id);
                $company = $context->company;

                $this->validateContext($lockedPeriod, $lockedEmployee, $date);
                $this->authorize($currentActor, $company);
                if ($batchItem !== null && ($linked = OvertimeDecision::withoutCompanyScope()
                    ->where('batch_item_id', $batchItem->id)->lockForUpdate()->first())) {
                    return $this->verifiedLinkedDecision($linked, $batchItem, $lockedPeriod, $lockedEmployee, $date, $candidateKey, $decision, $reason, $currentActor);
                }

                $occurrence = $this->resolver->resolve($lockedEmployee, $date);
                $analysis = $this->analysisResolver->resolve($lockedEmployee, $occurrence, $calendarContext);
                $candidate = $analysis->overtimeCandidates->firstWhere('key', $candidateKey);

                if ($candidate === null || $candidate->rateMinutes->totalMinutes() !== $candidate->minutes) {
                    throw ValidationException::withMessages([
                        'candidate_key' => 'El candidato ya no coincide con las marcas y la jornada vigentes.',
                    ]);
                }

                $resolution = $this->resolution(
                    $occurrence, $candidate, $decision, $batchItem, $approvedStartsAt, $approvedEndsAt,
                );

                $previous = $this->decisionMatcher->overtime(
                    OvertimeDecision::withoutCompanyScope()
                        ->where('company_id', $company->id)
                        ->where('pay_period_id', $lockedPeriod->id)
                        ->where('employee_id', $lockedEmployee->id)
                        ->whereDate('work_date', $date->toDateString())
                        ->current()
                        ->lockForUpdate()
                        ->get(),
                    $candidate,
                );

                if ($previous !== null && (($resolution['record_version'] === 1 && $previous->decision === $decision)
                    || ($resolution['record_version'] === 2 && $previous->resolution_hash === $resolution['resolution_hash']))) {
                    throw ValidationException::withMessages([
                        'decision' => 'El candidato ya tiene esa decisión vigente.',
                    ]);
                }

                return $this->decisionAppender->append(
                    $previous,
                    $candidate,
                    fn (): OvertimeDecision => OvertimeDecision::withoutCompanyScope()->create([
                        ...$resolution,
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
                        'batch_item_id' => $batchItem?->id,
                    ]),
                );
            },
        );
    }

    private function verifiedLinkedDecision(
        OvertimeDecision $linked,
        OvertimeDecisionBatchItem $item,
        PayPeriod $period,
        Employee $employee,
        CarbonImmutable $date,
        string $candidateKey,
        string $decision,
        string $reason,
        User $actor,
    ): OvertimeDecision {
        if ($linked->company_id !== $period->company_id || $linked->pay_period_id !== $period->id
            || $linked->employee_id !== $employee->id || ! $linked->work_date->isSameDay($date)
            || $linked->candidate_key !== $candidateKey || $linked->fingerprint !== $item->fingerprint
            || $linked->decision !== $decision || $linked->reason !== $reason || $linked->decided_by !== $actor->id) {
            throw new LogicException('Linked batch decision does not match its immutable context.');
        }

        return $linked;
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

    private function resolution(
        ShiftOccurrence $occurrence,
        AttendanceSegment $candidate,
        string $decision,
        ?OvertimeDecisionBatchItem $batchItem,
        CarbonInterface|string|null $approvedStartsAt,
        CarbonInterface|string|null $approvedEndsAt,
    ): array {
        $partial = $approvedStartsAt !== null || $approvedEndsAt !== null;
        if ($occurrence->payrollPolicyKey !== 'duration-first-v2') {
            if ($partial) {
                throw ValidationException::withMessages(['approved_interval' => 'La aprobación parcial solo está disponible para candidatos de duración primero.']);
            }

            return ['record_version' => 1];
        }
        if (($approvedStartsAt === null) !== ($approvedEndsAt === null) || ($partial && $batchItem !== null)) {
            throw ValidationException::withMessages(['approved_interval' => 'El intervalo parcial no es válido para esta solicitud.']);
        }

        $zero = new BandSplit;
        $approvedStart = $partial ? CarbonImmutable::parse($approvedStartsAt) : null;
        $approvedEnd = $partial ? CarbonImmutable::parse($approvedEndsAt) : null;
        if ($partial && ($decision !== OvertimeDecision::APPROVED
            || $approvedStart->second !== 0 || $approvedEnd->second !== 0
            || $approvedStart->lt($candidate->start) || $approvedEnd->gt($candidate->end)
            || ! $approvedEnd->gt($approvedStart)
            || ($approvedStart->equalTo($candidate->start) && $approvedEnd->equalTo($candidate->end)))) {
            throw ValidationException::withMessages(['approved_interval' => 'Seleccione un subintervalo contiguo de minutos completos dentro del candidato.']);
        }

        if ($partial) {
            $approved = $this->ratesFor($candidate, $approvedStart, $approvedEnd);
            $before = $this->ratesFor($candidate, $candidate->start, $approvedStart);
            $after = $this->ratesFor($candidate, $approvedEnd, $candidate->end);
            $rejected = $before->plus($after);
            $kind = OvertimeDecision::PARTIAL;
        } elseif ($decision === OvertimeDecision::APPROVED) {
            [$approved, $before, $after, $rejected, $kind] = [$candidate->rateMinutes, $zero, $zero, $zero, OvertimeDecision::WHOLE_APPROVE];
            [$approvedStart, $approvedEnd] = [$candidate->start, $candidate->end];
        } else {
            [$approved, $before, $after, $rejected, $kind] = [$zero, $zero, $zero, $candidate->rateMinutes, OvertimeDecision::WHOLE_REJECT];
        }

        $approvedRates = $this->rateMinutes($approved);
        $rejectedRates = $this->rateMinutes($rejected);
        if ($approved->plus($rejected) != $candidate->rateMinutes) {
            throw ValidationException::withMessages(['approved_interval' => 'El intervalo no conserva todas las bandas del candidato.']);
        }
        $data = [
            'record_version' => 2,
            'resolution_kind' => $kind,
            'approved_starts_at' => $approvedStart,
            'approved_ends_at' => $approvedEnd,
            'rejected_before_starts_at' => $before->totalMinutes() > 0 ? $candidate->start : null,
            'rejected_before_ends_at' => $before->totalMinutes() > 0 ? $approvedStart : null,
            'rejected_after_starts_at' => $after->totalMinutes() > 0 ? $approvedEnd : null,
            'rejected_after_ends_at' => $after->totalMinutes() > 0 ? $candidate->end : null,
            'approved_minutes' => $approved->totalMinutes(),
            'rejected_minutes' => $rejected->totalMinutes(),
            'rejected_before_minutes' => $before->totalMinutes(),
            'rejected_after_minutes' => $after->totalMinutes(),
            'approved_rate_minutes' => $approvedRates,
            'rejected_rate_minutes' => $rejectedRates,
        ];
        $data['resolution_hash'] = hash('sha256', json_encode([
            $candidate->key, $decision, $kind,
            $approvedStart?->toDateTimeString(), $approvedEnd?->toDateTimeString(),
            $data['approved_minutes'], $data['rejected_before_minutes'], $data['rejected_after_minutes'],
            $approvedRates, $rejectedRates,
        ], JSON_THROW_ON_ERROR));

        return $data;
    }

    private function ratesFor(AttendanceSegment $candidate, CarbonImmutable $start, CarbonImmutable $end): BandSplit
    {
        $minutes = (int) floor($start->diffInSeconds($end) / 60);
        if ($candidate->rateMinutes->extra100Minutes === $candidate->minutes) {
            return new BandSplit(extra100Minutes: $minutes);
        }

        return $this->bandSplitter->split($start, $end, self::DURATION_FIRST_OVERTIME_BANDS);
    }
}
