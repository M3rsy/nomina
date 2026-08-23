<?php

namespace App\Services\Auditoria;

use App\Models\AttendanceException;
use App\Models\AuditLogEntry;
use App\Models\Employee;
use App\Models\EmployeeRevision;
use App\Models\EmployeeScheduleAssignment;
use App\Models\JustifiedAbsence;
use App\Models\LoginAttempt;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AuditEntryProjector
{
    /** @var array<int, string|null> */
    private array $userEmailCache = [];

    public const PROJECTED_TYPES = [
        'login_attempt',
        'employee_revision',
        'schedule_assignment',
        'mark_revision',
        'overtime_decision',
        'attendance_exception',
        'full_day_absence',
        'payroll_state',
    ];

    public function project(Model $source): ?AuditLogEntry
    {
        if (! Schema::hasTable('audit_entries')) {
            return null;
        }

        $payload = match (true) {
            $source instanceof LoginAttempt => $this->loginAttempt($source),
            $source instanceof EmployeeRevision => $this->employeeRevision($source),
            $source instanceof EmployeeScheduleAssignment => $this->scheduleAssignment($source),
            $source instanceof OvertimeDecision => $this->overtimeDecision($source),
            $source instanceof AttendanceException => $this->attendanceException($source),
            default => null,
        };

        if ($payload === null) {
            return null;
        }

        $now = now();
        AuditLogEntry::query()->upsert(
            [[
                ...$payload,
                'metadata' => is_array($payload['metadata'] ?? null)
                    ? json_encode($payload['metadata'], JSON_THROW_ON_ERROR)
                    : ($payload['metadata'] ?? null),
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
                'source_revision' => 'created',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['source_type', 'source_id', 'source_revision'],
            [
                'company_id',
                'type',
                'occurred_at',
                'actor_id',
                'user_identifier',
                'description',
                'metadata',
                'subject_type',
                'subject_id',
                'updated_at',
            ],
        );

        return null;
    }

    public function projectMetadata(Model $source): int
    {
        if (! Schema::hasTable('audit_entries')) {
            return 0;
        }

        $entries = match (true) {
            $source instanceof RawMark => $this->rawMarkRevisions($source),
            $source instanceof JustifiedAbsence => $this->fullDayAbsenceRevisions($source),
            $source instanceof PayPeriod => $this->payrollStateEvents($source),
            default => [],
        };

        foreach ($entries as $entry) {
            AuditLogEntry::query()->updateOrCreate(
                [
                    'source_type' => $source::class,
                    'source_id' => $source->getKey(),
                    'source_revision' => $entry['source_revision'],
                ],
                $entry + [
                    'source_type' => $source::class,
                    'source_id' => $source->getKey(),
                ],
            );
        }

        return count($entries);
    }

    /** @return array<string, mixed> */
    private function loginAttempt(LoginAttempt $attempt): array
    {
        $attempt->loadMissing(['company', 'user']);

        return [
            'company_id' => $attempt->company_id,
            'type' => 'login_attempt',
            'occurred_at' => $attempt->created_at,
            'actor_id' => $attempt->user_id,
            'user_identifier' => $attempt->email,
            'description' => ($attempt->success ? 'Éxito' : 'Fallido').' desde IP '.$attempt->ip,
            'metadata' => ['ip' => $attempt->ip, 'success' => $attempt->success],
            'subject_type' => $attempt->user_id ? User::class : null,
            'subject_id' => $attempt->user_id,
        ];
    }

    /** @return array<string, mixed> */
    private function employeeRevision(EmployeeRevision $revision): array
    {
        $revision->loadMissing(['employee.company', 'user']);

        return [
            'company_id' => $revision->employee?->company_id,
            'type' => 'employee_revision',
            'occurred_at' => $revision->created_at,
            'actor_id' => $revision->user_id,
            'user_identifier' => $revision->user?->email,
            'description' => "Empleado #{$revision->employee_id}: campo {$revision->field} de '{$revision->old_value}' a '{$revision->new_value}'".($revision->reason ? " ({$revision->reason})" : ''),
            'metadata' => ['employee_id' => $revision->employee_id, 'field' => $revision->field],
            'subject_type' => Employee::class,
            'subject_id' => $revision->employee_id,
        ];
    }

    /** @return array<string, mixed> */
    private function scheduleAssignment(EmployeeScheduleAssignment $assignment): array
    {
        $assignment->loadMissing(['company', 'employee', 'profile', 'assigner']);

        $until = $assignment->effective_to?->format('d/m/Y') ?? 'sin fecha de fin';

        return [
            'company_id' => $assignment->company_id,
            'type' => 'schedule_assignment',
            'occurred_at' => $assignment->created_at,
            'actor_id' => $assignment->assigned_by,
            'user_identifier' => $assignment->assigner?->email,
            'description' => "{$assignment->employee?->full_name}: {$assignment->profile?->name} v{$assignment->profile?->version} desde {$assignment->effective_from->format('d/m/Y')} hasta {$until}. Motivo: {$assignment->reason}",
            'metadata' => ['assignment_id' => $assignment->id, 'employee_id' => $assignment->employee_id],
            'subject_type' => Employee::class,
            'subject_id' => $assignment->employee_id,
        ];
    }

    /** @return array<string, mixed> */
    private function overtimeDecision(OvertimeDecision $decision): array
    {
        $decision->loadMissing(['company', 'employee', 'decider']);
        $label = $decision->decision === OvertimeDecision::APPROVED ? 'aprobó' : 'rechazó';

        return [
            'company_id' => $decision->company_id,
            'type' => 'overtime_decision',
            'occurred_at' => $decision->created_at,
            'actor_id' => $decision->decided_by,
            'user_identifier' => $decision->decider?->email,
            'description' => "{$decision->employee?->full_name}: {$label} {$decision->minutes} min del {$decision->work_date->format('d/m/Y')} ({$decision->starts_at->format('H:i')}–{$decision->ends_at->format('H:i')}). Motivo: {$decision->reason}",
            'metadata' => ['decision_id' => $decision->id, 'candidate_key' => $decision->candidate_key],
            'subject_type' => Employee::class,
            'subject_id' => $decision->employee_id,
        ];
    }

    /** @return array<string, mixed> */
    private function attendanceException(AttendanceException $exception): array
    {
        $exception->loadMissing(['company', 'employee', 'decider']);
        $label = $exception->decision === AttendanceException::GRANTED ? 'concedió' : 'revocó';
        $interval = $exception->starts_at !== null && $exception->ends_at !== null
            ? " ({$exception->starts_at->format('H:i')}–{$exception->ends_at->format('H:i')})"
            : '';

        return [
            'company_id' => $exception->company_id,
            'type' => 'attendance_exception',
            'occurred_at' => $exception->created_at,
            'actor_id' => $exception->decided_by,
            'user_identifier' => $exception->decider?->email,
            'description' => "{$exception->employee?->full_name}: {$label} {$exception->minutes} min del {$exception->work_date->format('d/m/Y')}{$interval}. Motivo: {$exception->reason}",
            'metadata' => ['exception_id' => $exception->id, 'deficit_key' => $exception->deficit_key],
            'subject_type' => Employee::class,
            'subject_id' => $exception->employee_id,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rawMarkRevisions(RawMark $rawMark): array
    {
        $rawMark->loadMissing(['company']);

        return collect($rawMark->metadata['revisions'] ?? [])
            ->filter(fn (mixed $revision): bool => is_array($revision))
            ->map(fn (array $revision): array => [
                'company_id' => $rawMark->company_id,
                'type' => 'mark_revision',
                'occurred_at' => $this->metadataOccurredAt($rawMark, $revision),
                'actor_id' => $revision['user_id'] ?? null,
                'user_identifier' => $this->userEmail($revision['user_id'] ?? null),
                'description' => $this->describeMarkRevision($rawMark, $revision),
                'metadata' => [
                    'raw_mark_id' => $rawMark->id,
                    'action' => $revision['action'] ?? null,
                    'revision' => $revision,
                ],
                'subject_type' => Employee::class,
                'subject_id' => $rawMark->employee_id,
                'source_revision' => $this->metadataSourceRevision('mark_revision', $revision),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function fullDayAbsenceRevisions(JustifiedAbsence $absence): array
    {
        $absence->loadMissing(['company', 'employee']);

        return collect($absence->metadata['revisions'] ?? [])
            ->filter(fn (mixed $revision): bool => is_array($revision))
            ->map(fn (array $revision): array => [
                'company_id' => $absence->company_id,
                'type' => 'full_day_absence',
                'occurred_at' => $this->metadataOccurredAt($absence, $revision),
                'actor_id' => $revision['user_id'] ?? null,
                'user_identifier' => $this->userEmail($revision['user_id'] ?? null),
                'description' => $this->describeFullDayAbsenceRevision($absence, $revision),
                'metadata' => [
                    'justified_absence_id' => $absence->id,
                    'employee_id' => $absence->employee_id,
                    'action' => $revision['action'] ?? 'justify_full_day_absence',
                    'old_values' => $revision['old_values'] ?? null,
                    'new_values' => $revision['new_values'] ?? null,
                    'revision' => $revision,
                ],
                'subject_type' => Employee::class,
                'subject_id' => $absence->employee_id,
                'source_revision' => $this->metadataSourceRevision('full_day_absence', $revision),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function payrollStateEvents(PayPeriod $payPeriod): array
    {
        $payPeriod->loadMissing('company');
        $metadata = $payPeriod->metadata ?? [];
        $entries = [];

        foreach (['approved', 'exported', 'processed'] as $action) {
            $at = $metadata[$action.'_at'] ?? null;
            if ($at === null) {
                continue;
            }

            $userId = $metadata[$action.'_by'] ?? null;
            $event = [
                'action' => $action,
                'at' => $at,
                'user_id' => $userId,
                'status' => $action,
            ];
            $entries[] = [
                'company_id' => $payPeriod->company_id,
                'type' => 'payroll_state',
                'occurred_at' => Carbon::parse($at),
                'actor_id' => $userId,
                'user_identifier' => $this->userEmail($userId),
                'description' => "Período {$payPeriod->name} cambió a estado {$action}",
                'metadata' => ['pay_period_id' => $payPeriod->id, 'status' => $action, 'event' => $event],
                'subject_type' => PayPeriod::class,
                'subject_id' => $payPeriod->id,
                'source_revision' => $this->metadataSourceRevision('payroll_state', $event),
            ];
        }

        foreach ($metadata['reopenings'] ?? [] as $reopening) {
            if (! is_array($reopening) || ($reopening['at'] ?? null) === null) {
                continue;
            }

            $userId = $reopening['user_id'] ?? null;
            $invalidated = (int) ($reopening['invalidated_results'] ?? 0);
            $reason = $reopening['reason'] ?? 'Sin motivo registrado';
            $entries[] = [
                'company_id' => $payPeriod->company_id,
                'type' => 'payroll_state',
                'occurred_at' => Carbon::parse($reopening['at']),
                'actor_id' => $userId,
                'user_identifier' => $this->userEmail($userId),
                'description' => "Período {$payPeriod->name} reabierto de procesado a validación. Motivo: {$reason}. {$invalidated} resultados invalidados",
                'metadata' => ['pay_period_id' => $payPeriod->id, 'status' => 'reopened', 'event' => $reopening],
                'subject_type' => PayPeriod::class,
                'subject_id' => $payPeriod->id,
                'source_revision' => $this->metadataSourceRevision('payroll_state_reopening', $reopening),
            ];
        }

        return $entries;
    }

    private function metadataSourceRevision(string $type, array $event): string
    {
        return hash('sha256', $type.'|'.json_encode($this->canonicalize($event), JSON_THROW_ON_ERROR));
    }

    private function metadataOccurredAt(Model $source, array $event): Carbon
    {
        $timestamp = $event['at'] ?? null;

        if (is_string($timestamp) && trim($timestamp) !== '') {
            return Carbon::parse($timestamp);
        }

        // Some old metadata rows may lack their own timestamp. The projection is
        // still reconstructible: the source_revision is content-addressed, while
        // occurred_at falls back to the immutable source creation timestamp.
        return Carbon::parse($source->created_at ?? $source->updated_at ?? now());
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function userEmail(mixed $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        $id = (int) $userId;

        return $this->userEmailCache[$id] ??= User::query()->whereKey($id)->value('email');
    }

    private function describeMarkRevision(RawMark $rawMark, array $revision): string
    {
        $prefix = "Marca #{$rawMark->id} (empleado {$rawMark->employee_external_id})";
        $reason = $revision['reason'] ?? 'Sin motivo registrado';
        $oldEventAt = $revision['old_event_at'] ?? 'desconocida';
        $newEventAt = $revision['new_event_at'] ?? 'desconocida';
        $previousStatus = $revision['previous_status'] ?? 'desconocido';
        $newStatus = $revision['new_status'] ?? match ($revision['action'] ?? null) {
            'mark_corrected' => 'corrected',
            'delete' => 'deleted',
            default => 'desconocido',
        };

        return match ($revision['action'] ?? null) {
            'manual_create' => "Marca manual #{$rawMark->id} (empleado {$rawMark->employee_external_id}) creada para ".($revision['work_date'] ?? 'fecha no registrada').' a '.($revision['event_at'] ?? $rawMark->event_at?->toDateTimeString() ?? 'hora no registrada').". Motivo: {$reason}",
            'edit_event_at' => "{$prefix}: fecha/hora de {$oldEventAt} a {$newEventAt}. Motivo: {$reason}",
            'assign_employee' => "{$prefix}: empleado de ".($revision['previous_employee_id'] ?? 'desconocido').' a '.($revision['new_employee_id'] ?? 'desconocido').". Motivo: {$reason}",
            'mark_corrected' => "{$prefix}: estado de {$previousStatus} a {$newStatus}. Motivo: {$reason}",
            'delete' => "{$prefix}: estado de {$previousStatus} a {$newStatus}. Motivo: {$reason}",
            default => "{$prefix}: acción ".($revision['action'] ?? 'desconocida').". Motivo: {$reason}",
        };
    }

    private function describeFullDayAbsenceRevision(JustifiedAbsence $absence, array $revision): string
    {
        $employee = $absence->employee?->full_name ?? "Empleado #{$absence->employee_id}";
        $date = $absence->date->format('d/m/Y');
        $oldValues = is_array($revision['old_values'] ?? null) ? $revision['old_values'] : null;
        $newValues = is_array($revision['new_values'] ?? null) ? $revision['new_values'] : [];
        $verb = $oldValues === null ? 'autorizó' : 'actualizó';

        return "{$employee}: {$verb} la justificación de jornada completa del {$date}. "
            .'Antes: '.$this->describeFullDayAbsenceValues($oldValues).'. '
            .'Ahora: '.$this->describeFullDayAbsenceValues($newValues).'.';
    }

    private function describeFullDayAbsenceValues(?array $values): string
    {
        if ($values === null) {
            return 'sin justificación previa';
        }

        $rates = collect([
            'Ordinario' => $values['rate_minutes']['ordinary'] ?? 0,
            '25%' => $values['rate_minutes']['extra25'] ?? 0,
            '50%' => $values['rate_minutes']['extra50'] ?? 0,
            '75%' => $values['rate_minutes']['extra75'] ?? 0,
            '100%' => $values['rate_minutes']['extra100'] ?? 0,
        ])->filter(fn ($minutes) => (int) $minutes > 0)
            ->map(fn ($minutes, $label) => "{$label}: ".(int) $minutes.' min')
            ->implode(', ');
        $start = $this->formatAbsenceAuditDateTime($values['scheduled_start'] ?? null);
        $end = $this->formatAbsenceAuditDateTime($values['scheduled_end'] ?? null);
        $minutes = (int) ($values['scheduled_minutes'] ?? 0);
        $reason = $values['reason'] ?? 'sin motivo registrado';
        $notes = $values['notes'] ?? 'sin notas';
        $fingerprint = $values['schedule_fingerprint'] ?? 'sin huella';

        return "{$start} → {$end}, {$minutes} min ({$rates}), motivo {$reason}, notas: {$notes}, huella: {$fingerprint}";
    }

    private function formatAbsenceAuditDateTime(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'sin horario';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }
}
