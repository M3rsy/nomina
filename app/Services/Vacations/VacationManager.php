<?php

namespace App\Services\Vacations;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\Vacation;
use App\Models\VacationBalanceMovement;
use App\Models\VacationDay;
use App\Services\Attendance\AttendanceShiftAnalyzer;
use App\Services\Attendance\FullDayAbsenceSnapshot;
use App\Services\Attendance\ShiftOccurrence;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\CurrentCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VacationManager
{
    public function __construct(
        private ShiftOccurrenceResolver $occurrences,
        private AttendanceShiftAnalyzer $analyzer,
    ) {}

    public function approve(
        Company $company,
        Employee $employee,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?string $notes,
        User $actor,
    ): Vacation {
        $this->authorizeActor($actor, $company);
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        }

        return DB::transaction(function () use ($company, $employee, $start, $end, $notes, $actor): Vacation {
            $company = Company::query()->whereKey($company->id)->orderBy('id')->lockForUpdate()->firstOrFail();
            $employee = Employee::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->whereKey($employee->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($employee === null || ! $employee->is_active) {
                throw ValidationException::withMessages(['employee_id' => 'Seleccioná un empleado activo de la empresa actual.']);
            }

            $this->lockAffectedPeriods($company->id, $start, $end);
            Vacation::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->where('employee_id', $employee->id)
                ->where('status', Vacation::APPROVED)
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (Vacation::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->where('employee_id', $employee->id)
                ->where('status', Vacation::APPROVED)
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->exists()) {
                throw ValidationException::withMessages(['start_date' => 'El rango se superpone con vacaciones vigentes del empleado.']);
            }

            VacationDay::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', '>=', $start->toDateString())
                ->whereDate('work_date', '<=', $end->toDateString())
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $holidayDates = Holiday::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->get(['date', 'name'])
                ->mapWithKeys(fn (Holiday $holiday): array => [$holiday->date->toDateString() => $holiday->name]);
            $holidayGenerations = DB::table('holiday_calendar_generations')
                ->where('company_id', $company->id)
                ->whereBetween('calendar_date', [$start->toDateString(), $end->toDateString()])
                ->pluck('generation', 'calendar_date');
            $daySnapshots = [];
            $excluded = [];

            for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                $key = $date->toDateString();

                if ($holidayDates->has($key)) {
                    $excluded[] = ['date' => $key, 'reason' => 'holiday', 'label' => (string) $holidayDates[$key]];

                    continue;
                }

                $occurrence = $this->occurrences->resolve($employee, $date);

                if ($occurrence->schedule === null || $occurrence->assignment === null || $occurrence->publicationId === null) {
                    throw ValidationException::withMessages([
                        'start_date' => "No se pudo resolver una única jornada publicada para {$key}.",
                    ]);
                }

                if (! $occurrence->schedule->is_working_day
                    || $occurrence->scheduledStart === null
                    || $occurrence->scheduledEnd === null) {
                    $excluded[] = ['date' => $key, 'reason' => 'rest_day', 'label' => 'Día de descanso'];

                    continue;
                }

                $generation = (int) ($holidayGenerations[$key] ?? 0);
                // Vacations can be approved after marks have already arrived. Capture the
                // payable schedule using the same mark-free path payroll uses for a full-day absence.
                $markFreeOccurrence = new ShiftOccurrence(
                    workDate: $occurrence->workDate,
                    assignment: $occurrence->assignment,
                    schedule: $occurrence->schedule,
                    scheduledStart: $occurrence->scheduledStart,
                    scheduledEnd: $occurrence->scheduledEnd,
                    marks: collect(),
                    status: ShiftOccurrence::NO_MARKS,
                    factGeneration: $occurrence->factGeneration,
                    publicationId: $occurrence->publicationId,
                    payrollPolicyKey: $occurrence->payrollPolicyKey,
                );
                $analysis = $this->analyzer->analyze($markFreeOccurrence, false, $generation);
                $snapshot = FullDayAbsenceSnapshot::from($markFreeOccurrence, $analysis);
                $daySnapshots[] = [
                    'work_date' => $key,
                    'scheduled_start' => $snapshot->scheduledStart,
                    'scheduled_end' => $snapshot->scheduledEnd,
                    'planned_minutes' => $snapshot->scheduledMinutes,
                    'rate_minutes' => $snapshot->rateMinutes,
                    'snapshot_fingerprint' => $snapshot->fingerprint,
                    'holiday_generation' => $generation,
                    'employee_schedule_assignment_id' => $occurrence->assignment->id,
                    'work_schedule_id' => $occurrence->schedule->id,
                    'work_schedule_profile_publication_id' => $occurrence->publicationId,
                ];
            }

            if ($daySnapshots === []) {
                throw ValidationException::withMessages(['start_date' => 'El rango no contiene jornadas laborables para el empleado.']);
            }

            $vacation = Vacation::withoutCompanyScope()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'start_date' => $start,
                'end_date' => $end,
                'status' => Vacation::APPROVED,
                'notes' => filled($notes) ? trim((string) $notes) : null,
                'excluded_dates' => $excluded,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            foreach ($daySnapshots as $snapshot) {
                $day = VacationDay::withoutCompanyScope()->create([
                    'company_id' => $company->id,
                    'vacation_id' => $vacation->id,
                    'employee_id' => $employee->id,
                    'active_marker' => true,
                ] + $snapshot);
                $movement = VacationBalanceMovement::withoutCompanyScope()->create([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'vacation_id' => $vacation->id,
                    'vacation_day_id' => $day->id,
                    'type' => VacationBalanceMovement::VACATION_CONSUMPTION,
                    'days' => -1,
                    'reason' => 'Consumo por vacación pagada del '.$day->work_date->format('d/m/Y'),
                    'recorded_by' => $actor->id,
                ]);
                $this->auditMovement($movement, $actor);
            }

            $this->auditVacation($vacation, $actor, 'approved');

            return $vacation->load(['employee', 'days']);
        });
    }

    public function cancel(Vacation $vacation, string $reason, User $actor): Vacation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['cancellation_reason' => 'El motivo de cancelación es obligatorio.']);
        }

        $company = Company::query()->findOrFail($vacation->company_id);
        $this->authorizeActor($actor, $company);

        return DB::transaction(function () use ($vacation, $reason, $actor, $company): Vacation {
            Company::query()->whereKey($company->id)->orderBy('id')->lockForUpdate()->firstOrFail();
            $vacation = Vacation::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->whereKey($vacation->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->firstOrFail();

            if ($vacation->status === Vacation::CANCELLED) {
                return $vacation->load(['employee', 'days']);
            }

            $days = VacationDay::withoutCompanyScope()
                ->where('vacation_id', $vacation->id)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->lockAffectedPeriods($company->id, $vacation->start_date, $vacation->end_date);

            foreach ($days as $day) {
                $day->forceFill(['active_marker' => null])->save();
                $movement = VacationBalanceMovement::withoutCompanyScope()->create([
                    'company_id' => $company->id,
                    'employee_id' => $vacation->employee_id,
                    'vacation_id' => $vacation->id,
                    'vacation_day_id' => $day->id,
                    'type' => VacationBalanceMovement::VACATION_REVERSAL,
                    'days' => 1,
                    'reason' => 'Reversión por cancelación: '.$reason,
                    'recorded_by' => $actor->id,
                ]);
                $this->auditMovement($movement, $actor);
            }

            $vacation->forceFill([
                'status' => Vacation::CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $this->auditVacation($vacation, $actor, 'cancelled');

            return $vacation->load(['employee', 'days']);
        });
    }

    public function adjustBalance(Company $company, Employee $employee, int $days, string $reason, User $actor): VacationBalanceMovement
    {
        $this->authorizeActor($actor, $company);
        $reason = trim($reason);

        if ($days === 0) {
            throw ValidationException::withMessages(['adjustment_days' => 'El ajuste debe ser distinto de cero.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['adjustment_reason' => 'El motivo del ajuste es obligatorio.']);
        }

        return DB::transaction(function () use ($company, $employee, $days, $reason, $actor): VacationBalanceMovement {
            Company::query()->whereKey($company->id)->orderBy('id')->lockForUpdate()->firstOrFail();
            $employee = Employee::withoutCompanyScope()
                ->where('company_id', $company->id)
                ->whereKey($employee->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($employee === null) {
                throw ValidationException::withMessages(['employee_id' => 'El empleado no pertenece a la empresa actual.']);
            }

            $movement = VacationBalanceMovement::withoutCompanyScope()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'type' => VacationBalanceMovement::MANUAL_ADJUSTMENT,
                'days' => $days,
                'reason' => $reason,
                'recorded_by' => $actor->id,
            ]);
            $this->auditMovement($movement, $actor);

            return $movement;
        });
    }

    public function balance(Employee $employee): int
    {
        return (int) VacationBalanceMovement::withoutCompanyScope()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->sum('days');
    }

    private function authorizeActor(User $actor, Company $company): void
    {
        $activeCompany = app(CurrentCompany::class)->get();
        $activeCompanyId = $activeCompany !== null
            && Company::query()->whereKey($activeCompany->id)->where('is_active', true)->exists()
                ? $activeCompany->id
                : null;

        if (! $actor->can('vacations.manage')
            || $activeCompanyId === null
            || $company->id !== $activeCompanyId
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $activeCompanyId)) {
            throw new AuthorizationException('No tenés permiso para gestionar vacaciones de esta empresa.');
        }
    }

    private function lockAffectedPeriods(int $companyId, CarbonInterface $start, CarbonInterface $end): void
    {
        $periods = PayPeriod::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($periods->contains(fn (PayPeriod $period): bool => in_array($period->status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true))) {
            throw ValidationException::withMessages([
                'vacation' => 'Las vacaciones no pueden cambiar porque afectan un período de nómina bloqueado. Reabrí el período primero.',
            ]);
        }
    }

    private function auditVacation(Vacation $vacation, User $actor, string $revision): void
    {
        $employee = Employee::withoutCompanyScope()->find($vacation->employee_id);
        $verb = $revision === 'approved' ? 'aprobó' : 'canceló';
        $suffix = $revision === 'cancelled' ? '. Motivo: '.$vacation->cancellation_reason : '';

        AuditLogEntry::query()->updateOrCreate([
            'source_type' => Vacation::class,
            'source_id' => $vacation->id,
            'source_revision' => $revision,
        ], [
            'company_id' => $vacation->company_id,
            'type' => 'vacation',
            'occurred_at' => $revision === 'approved' ? $vacation->approved_at : $vacation->cancelled_at,
            'actor_id' => $actor->id,
            'user_identifier' => $actor->email,
            'description' => sprintf(
                '%s vacaciones pagadas de %s del %s al %s (%d jornadas)%s',
                ucfirst($verb),
                $employee?->full_name ?? 'empleado #'.$vacation->employee_id,
                $vacation->start_date->format('d/m/Y'),
                $vacation->end_date->format('d/m/Y'),
                $vacation->days()->count(),
                $suffix,
            ),
            'metadata' => [
                'vacation_id' => $vacation->id,
                'employee_id' => $vacation->employee_id,
                'status' => $vacation->status,
            ],
            'subject_type' => Employee::class,
            'subject_id' => $vacation->employee_id,
        ]);
    }

    private function auditMovement(VacationBalanceMovement $movement, User $actor): void
    {
        AuditLogEntry::query()->updateOrCreate([
            'source_type' => VacationBalanceMovement::class,
            'source_id' => $movement->id,
            'source_revision' => 'created',
        ], [
            'company_id' => $movement->company_id,
            'type' => 'vacation_balance',
            'occurred_at' => $movement->created_at,
            'actor_id' => $actor->id,
            'user_identifier' => $actor->email,
            'description' => "Movimiento de saldo de vacaciones: {$movement->days} día(s). {$movement->reason}",
            'metadata' => [
                'movement_id' => $movement->id,
                'employee_id' => $movement->employee_id,
                'vacation_id' => $movement->vacation_id,
                'type' => $movement->type,
                'days' => $movement->days,
            ],
            'subject_type' => Employee::class,
            'subject_id' => $movement->employee_id,
        ]);
    }
}
