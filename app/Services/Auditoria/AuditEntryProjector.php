<?php

namespace App\Services\Auditoria;

use App\Models\AttendanceException;
use App\Models\AuditLogEntry;
use App\Models\Employee;
use App\Models\EmployeeRevision;
use App\Models\EmployeeScheduleAssignment;
use App\Models\LoginAttempt;
use App\Models\OvertimeDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AuditEntryProjector
{
    public const PROJECTED_TYPES = [
        'login_attempt',
        'employee_revision',
        'schedule_assignment',
        'overtime_decision',
        'attendance_exception',
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

        return AuditLogEntry::query()->updateOrCreate(
            [
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
                'source_revision' => 'created',
            ],
            $payload + [
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
                'source_revision' => 'created',
            ],
        );
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

        return [
            'company_id' => $exception->company_id,
            'type' => 'attendance_exception',
            'occurred_at' => $exception->created_at,
            'actor_id' => $exception->decided_by,
            'user_identifier' => $exception->decider?->email,
            'description' => "{$exception->employee?->full_name}: {$label} {$exception->minutes} min del {$exception->work_date->format('d/m/Y')} ({$exception->starts_at->format('H:i')}–{$exception->ends_at->format('H:i')}). Motivo: {$exception->reason}",
            'metadata' => ['exception_id' => $exception->id, 'deficit_key' => $exception->deficit_key],
            'subject_type' => Employee::class,
            'subject_id' => $exception->employee_id,
        ];
    }
}
