<?php

namespace App\Services\Attendance;

use App\Models\PayPeriod;
use App\Models\WorkScheduleProfilePublication;
use App\Services\PayrollRules;
use Illuminate\Support\Collection;

final class AttendanceReviewSummaryReader
{
    private const DURATION_FIRST_SATURDAY_REQUIRED_MINUTES = 8 * 60;

    public function __construct(
        private PayrollPeriodReviewSnapshot $snapshots,
        private PayrollShiftEvaluator $shiftEvaluator,
    ) {}

    /**
     * @return Collection<int, array{
     *     employee_id:int,
     *     employee_name:string,
     *     employee_code:string|null,
     *     employee_external_id:string|null,
     *     required_minutes:int,
     *     worked_minutes:int,
     *     missing_minutes:int,
     *     overtime_minutes:int,
     *     absence_count:int,
     *     incomplete_count:int,
     *     ambiguous_count:int,
     *     status:string,
     *     status_label:string,
     *     rows:Collection<int, array<string, mixed>>
     * }>
     */
    public function forPeriod(
        PayPeriod $period,
        ?int $uploadedFileId = null,
        ?PayrollPeriodReviewSnapshotContext $snapshot = null,
    ): Collection {
        $snapshot ??= $this->snapshots->captureForPeriod($period);
        $summaries = [];

        $this->snapshots->forEachReview($snapshot, function (PayrollShiftReview $review) use (&$summaries, $uploadedFileId): void {
            if ($uploadedFileId !== null && ! $review->occurrence->marks->contains(
                fn ($mark): bool => $mark->uploaded_file_id === $uploadedFileId,
            )) {
                return;
            }

            $evaluation = $this->shiftEvaluator->evaluate(
                $review->occurrence,
                $review->analysis,
                $review->currentDecisions,
                $review->currentExceptions,
                $review->vacationDay,
                $review->vacationIsStale,
            );

            if ($evaluation->status === PayrollShiftEvaluation::SKIP) {
                return;
            }

            $row = $this->dayRow($review, $evaluation);
            $employeeId = $review->employee->id;
            $summary = $summaries[$employeeId] ?? [
                'employee_id' => $employeeId,
                'employee_name' => $review->employee->full_name,
                'employee_code' => $review->employee->external_id,
                'employee_external_id' => $review->employee->external_id,
                'required_minutes' => 0,
                'worked_minutes' => 0,
                'missing_minutes' => 0,
                'overtime_minutes' => 0,
                'absence_count' => 0,
                'incomplete_count' => 0,
                'ambiguous_count' => 0,
                'status' => 'complete',
                'status_label' => 'Completa',
                'rows' => collect(),
            ];

            $summary['required_minutes'] += $row['required_minutes'];
            $summary['worked_minutes'] += $row['worked_minutes'];
            $summary['missing_minutes'] += $row['missing_minutes'];
            $summary['overtime_minutes'] += $row['overtime_minutes'];
            $summary['absence_count'] += $row['status'] === 'absence' ? 1 : 0;
            $summary['incomplete_count'] += $row['status'] === 'incomplete' ? 1 : 0;
            $summary['ambiguous_count'] += $row['is_ambiguous'] ? 1 : 0;
            $summary['rows']->push($row);
            $summaries[$employeeId] = $summary;
        });

        return collect($summaries)
            ->map(function (array $summary): array {
                $summary['rows'] = $summary['rows']->sortBy('date')->values();
                $summary['status'] = $this->summaryStatus($summary);
                $summary['status_label'] = $this->statusLabel($summary['status']);

                return $summary;
            })
            ->sortBy([['employee_name', 'asc'], ['employee_id', 'asc']])
            ->values();
    }

    /** @return array<string, mixed> */
    private function dayRow(PayrollShiftReview $review, PayrollShiftEvaluation $evaluation): array
    {
        $analysis = $review->analysis;
        $occurrence = $review->occurrence;
        $isFreeDay = $analysis->isHoliday
            || $occurrence->workDate->dayOfWeek === PayrollRules::DAY_SUNDAY
            || ! $occurrence->schedule?->is_working_day;
        $missingMinutes = (int) $analysis->deficits->sum('minutes');
        $requiredMinutes = $isFreeDay
            ? 0
            : $this->requiredMinutes($review, $evaluation, $missingMinutes);
        $overtimeMinutes = max(
            $evaluation->detectedOvertimeMinutes,
            (int) $analysis->overtimeCandidates->sum('minutes'),
            $isFreeDay ? $analysis->scheduledRates->extra100Minutes : 0,
        );
        $status = $this->rowStatus($review, $evaluation, $missingMinutes, $overtimeMinutes);
        $isAmbiguous = in_array($occurrence->status, [
            ShiftOccurrence::AMBIGUOUS,
            ShiftOccurrence::MISSING_ASSIGNMENT,
            ShiftOccurrence::AMBIGUOUS_ASSIGNMENT,
            ShiftOccurrence::MISSING_SCHEDULE,
            ShiftOccurrence::MISSING_PUBLICATION,
            ShiftOccurrence::AMBIGUOUS_PUBLICATION,
        ], true);
        $incidentCode = $this->incidentCode($review, $evaluation, $status);
        $incidentLabel = $this->incidentLabel($incidentCode);
        $workedMinutes = $evaluation->workedMinutes ?: $analysis->workedMinutes;
        $differenceMinutes = $overtimeMinutes - $missingMinutes;

        return [
            'date' => $occurrence->workDate,
            'work_date' => $occurrence->workDate,
            'entry' => $evaluation->entryAt ?? $analysis->entryAt,
            'exit' => $evaluation->exitAt ?? $analysis->exitAt,
            'worked_minutes' => $workedMinutes,
            'worked' => $workedMinutes,
            'required_minutes' => $requiredMinutes,
            'required' => $requiredMinutes,
            'difference_minutes' => $differenceMinutes,
            'difference' => $differenceMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'overtime' => $overtimeMinutes,
            'missing_minutes' => $missingMinutes,
            'incident_code' => $incidentCode,
            'incident' => $incidentLabel,
            'incident_label' => $incidentLabel,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'is_ambiguous' => $isAmbiguous,
            'blockers' => $evaluation->blockers->pluck('code')->values()->all(),
        ];
    }

    private function requiredMinutes(
        PayrollShiftReview $review,
        PayrollShiftEvaluation $evaluation,
        int $missingMinutes,
    ): int {
        if ($review->occurrence->payrollPolicyKey === WorkScheduleProfilePublication::DURATION_FIRST_V2
            && $review->occurrence->workDate->dayOfWeek === PayrollRules::DAY_SATURDAY) {
            return self::DURATION_FIRST_SATURDAY_REQUIRED_MINUTES;
        }

        if ($evaluation->scheduledMinutes > 0) {
            return $evaluation->scheduledMinutes;
        }

        $analysis = $review->analysis;
        $required = $analysis->scheduledMinutes + $missingMinutes;
        if ($required > 0) {
            return $required;
        }

        if ($review->occurrence->scheduledStart !== null && $review->occurrence->scheduledEnd !== null) {
            return max(0, intdiv(
                (int) $review->occurrence->scheduledStart->diffInSeconds($review->occurrence->scheduledEnd),
                60,
            ));
        }

        return 0;
    }

    private function rowStatus(
        PayrollShiftReview $review,
        PayrollShiftEvaluation $evaluation,
        int $missingMinutes,
        int $overtimeMinutes,
    ): string {
        $occurrenceStatus = $review->occurrence->status;
        $inconsistent = in_array($occurrenceStatus, [
            ShiftOccurrence::AMBIGUOUS,
            ShiftOccurrence::MISSING_ASSIGNMENT,
            ShiftOccurrence::AMBIGUOUS_ASSIGNMENT,
            ShiftOccurrence::MISSING_SCHEDULE,
            ShiftOccurrence::MISSING_PUBLICATION,
            ShiftOccurrence::AMBIGUOUS_PUBLICATION,
        ], true) || in_array($review->analysis->status, [
            AttendanceShiftAnalysis::INVALID_INTERVAL,
            AttendanceShiftAnalysis::INVALID_RATE_BANDS,
            AttendanceShiftAnalysis::UNSUPPORTED_PAYROLL_POLICY,
        ], true);

        if ($inconsistent || ($evaluation->status === PayrollShiftEvaluation::BLOCKED
            && $evaluation->blockers->contains(fn (array $blocker): bool => ! in_array($blocker['code'] ?? null, [
                'pending_daily_shortfall',
                'pending_overtime_candidate',
                ShiftOccurrence::MISSING_PAIR,
            ], true)))) {
            return 'inconsistent';
        }

        if ($occurrenceStatus === ShiftOccurrence::MISSING_PAIR) {
            return 'incomplete';
        }

        if ($evaluation->isAbsence) {
            return 'absence';
        }

        if ($missingMinutes > 0) {
            return 'shortfall';
        }

        if ($overtimeMinutes > 0) {
            return 'overtime';
        }

        return 'complete';
    }

    /** @param array<string, mixed> $summary */
    private function summaryStatus(array $summary): string
    {
        $statuses = $summary['rows']->pluck('status');

        foreach (['inconsistent', 'incomplete', 'absence', 'shortfall', 'overtime'] as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return 'complete';
    }

    private function incidentCode(
        PayrollShiftReview $review,
        PayrollShiftEvaluation $evaluation,
        string $status,
    ): ?string {
        if ($status === 'incomplete') {
            return $review->occurrence->status;
        }

        if ($status === 'inconsistent') {
            return $evaluation->blockers->first()['code']
                ?? $review->analysis->status;
        }

        return $review->analysis->deficits->first()?->kind
            ?? ($review->analysis->overtimeCandidates->first()?->kind);
    }

    private function incidentLabel(?string $code): string
    {
        return match ($code) {
            'daily_shortfall' => 'Déficit de jornada',
            'full_day_absence' => 'Falta de jornada completa',
            'missing_pair' => 'Marca incompleta',
            'ambiguous' => 'Marcas ambiguas',
            'missing_assignment', 'ambiguous_assignment' => 'Asignación de jornada inconsistente',
            'missing_schedule' => 'Horario no configurado',
            'missing_publication', 'ambiguous_publication' => 'Publicación de jornada inconsistente',
            'invalid_interval' => 'Intervalo inválido',
            'invalid_rate_bands' => 'Bandas de recargo incompletas',
            'unsupported_payroll_policy' => 'Política de nómina no soportada',
            'pending_daily_shortfall' => 'Déficit pendiente de decisión',
            'pending_overtime_candidate' => 'Hora extra pendiente de decisión',
            'post_quota_overtime', 'non_working' => 'Horas extra detectadas',
            default => $code === null ? 'Sin incidencia' : $code,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'shortfall' => 'Déficit',
            'overtime' => 'Horas extra',
            'absence' => 'Falta',
            'incomplete' => 'Incompleta',
            'inconsistent' => 'Inconsistente',
            default => 'Completa',
        };
    }
}
