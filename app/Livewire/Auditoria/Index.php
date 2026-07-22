<?php

namespace App\Livewire\Auditoria;

use App\Models\AttendanceException;
use App\Models\EmployeeRevision;
use App\Models\EmployeeScheduleAssignment;
use App\Models\JustifiedAbsence;
use App\Models\LoginAttempt;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public const TYPES = [
        'all' => 'Todos',
        'login_attempt' => 'Intentos de inicio de sesión',
        'employee_revision' => 'Cambios en empleados',
        'schedule_assignment' => 'Asignaciones de jornada',
        'mark_revision' => 'Revisiones de marcas',
        'overtime_decision' => 'Autorizaciones de horas extra',
        'attendance_exception' => 'Excepciones de asistencia',
        'full_day_absence' => 'Justificaciones de jornada completa',
        'payroll_state' => 'Estados de nómina',
    ];

    #[Url]
    public string $type = 'all';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public ?string $user = null;

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function render()
    {
        $currentCompanyId = current_company_id();
        $companyIds = $currentCompanyId !== null
            ? [$currentCompanyId]
            : (auth()->user()->hasRole('super_admin') ? null : []);

        $entries = $this->collectEntries($companyIds);
        $entries = $this->applyFilters($entries);

        $page = $this->getPage();
        $perPage = 25;
        $total = count($entries);
        $items = array_slice($entries, ($page - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page']
        );

        return view('livewire.auditoria.index', [
            'entries' => $paginator,
            'types' => self::TYPES,
        ]);
    }

    private function collectEntries(?array $companyIds): array
    {
        $entries = [];
        $types = $this->type === 'all' ? array_keys(self::TYPES) : [$this->type];

        if (in_array('login_attempt', $types, true)) {
            LoginAttempt::query()
                ->with(['user', 'company'])
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
                ->latest('created_at')
                ->limit(500)
                ->get()
                ->each(function (LoginAttempt $attempt) use (&$entries): void {
                    $entries[] = new AuditEntry(
                        'login_attempt',
                        'Intento de inicio de sesión',
                        $attempt->created_at,
                        $attempt->company_id,
                        $attempt->company?->name ?? 'Sistema',
                        $attempt->user_id,
                        $attempt->email,
                        ($attempt->success ? 'Éxito' : 'Fallido').' desde IP '.$attempt->ip,
                        ['ip' => $attempt->ip, 'success' => $attempt->success]
                    );
                });
        }

        if (in_array('employee_revision', $types, true)) {
            EmployeeRevision::query()
                ->with(['user', 'employee.company'])
                ->when($companyIds !== null, function ($q) use ($companyIds): void {
                    $q->whereHas('employee', fn ($sq) => $sq->withoutGlobalScope('company')->whereIn('company_id', $companyIds));
                })
                ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
                ->latest('created_at')
                ->limit(500)
                ->get()
                ->each(function (EmployeeRevision $revision) use (&$entries): void {
                    $entries[] = new AuditEntry(
                        'employee_revision',
                        'Cambio en empleado',
                        $revision->created_at,
                        $revision->employee?->company_id,
                        $revision->employee?->company?->name ?? 'Desconocida',
                        $revision->user_id,
                        $revision->user?->email,
                        "Empleado #{$revision->employee_id}: campo {$revision->field} de '{$revision->old_value}' a '{$revision->new_value}'".($revision->reason ? " ({$revision->reason})" : ''),
                        ['employee_id' => $revision->employee_id, 'field' => $revision->field]
                    );
                });
        }

        if (in_array('schedule_assignment', $types, true)) {
            EmployeeScheduleAssignment::withoutCompanyScope()
                ->with(['company', 'employee', 'profile', 'assigner'])
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
                ->latest('created_at')
                ->limit(500)
                ->get()
                ->each(function (EmployeeScheduleAssignment $assignment) use (&$entries): void {
                    $until = $assignment->effective_to?->format('d/m/Y') ?? 'sin fecha de fin';
                    $entries[] = new AuditEntry(
                        'schedule_assignment',
                        'Asignación de jornada',
                        Carbon::parse($assignment->created_at),
                        $assignment->company_id,
                        $assignment->company?->name ?? 'Desconocida',
                        $assignment->assigned_by,
                        $assignment->assigner?->email,
                        "{$assignment->employee?->full_name}: {$assignment->profile?->name} v{$assignment->profile?->version} desde {$assignment->effective_from->format('d/m/Y')} hasta {$until}. Motivo: {$assignment->reason}",
                        ['assignment_id' => $assignment->id, 'employee_id' => $assignment->employee_id]
                    );
                });
        }

        if (in_array('overtime_decision', $types, true)) {
            OvertimeDecision::withoutCompanyScope()
                ->with(['company', 'employee', 'decider'])
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
                ->latest('created_at')
                ->limit(500)
                ->get()
                ->each(function (OvertimeDecision $decision) use (&$entries): void {
                    $label = $decision->decision === OvertimeDecision::APPROVED ? 'aprobó' : 'rechazó';
                    $entries[] = new AuditEntry(
                        'overtime_decision',
                        'Autorización de hora extra',
                        Carbon::parse($decision->created_at),
                        $decision->company_id,
                        $decision->company?->name ?? 'Desconocida',
                        $decision->decided_by,
                        $decision->decider?->email,
                        "{$decision->employee?->full_name}: {$label} {$decision->minutes} min del {$decision->work_date->format('d/m/Y')} ({$decision->starts_at->format('H:i')}–{$decision->ends_at->format('H:i')}). Motivo: {$decision->reason}",
                        ['decision_id' => $decision->id, 'candidate_key' => $decision->candidate_key]
                    );
                });
        }

        if (in_array('attendance_exception', $types, true)) {
            AttendanceException::withoutCompanyScope()
                ->with(['company', 'employee', 'decider'])
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
                ->latest('created_at')
                ->limit(500)
                ->get()
                ->each(function (AttendanceException $exception) use (&$entries): void {
                    $label = $exception->decision === AttendanceException::GRANTED ? 'concedió' : 'revocó';
                    $entries[] = new AuditEntry(
                        'attendance_exception',
                        'Excepción de asistencia',
                        Carbon::parse($exception->created_at),
                        $exception->company_id,
                        $exception->company?->name ?? 'Desconocida',
                        $exception->decided_by,
                        $exception->decider?->email,
                        "{$exception->employee?->full_name}: {$label} {$exception->minutes} min del {$exception->work_date->format('d/m/Y')} ({$exception->starts_at->format('H:i')}–{$exception->ends_at->format('H:i')}). Motivo: {$exception->reason}",
                        ['exception_id' => $exception->id, 'deficit_key' => $exception->deficit_key]
                    );
                });
        }

        if (in_array('full_day_absence', $types, true)) {
            JustifiedAbsence::withoutCompanyScope()
                ->with(['company', 'employee'])
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->whereNotNull('metadata')
                ->limit(500)
                ->get()
                ->each(function (JustifiedAbsence $absence) use (&$entries): void {
                    foreach ($absence->metadata['revisions'] ?? [] as $revision) {
                        $at = $revision['at'] ?? null;

                        if (! $at) {
                            continue;
                        }

                        $createdAt = Carbon::parse($at);

                        if (! $this->dateIsVisible($createdAt)) {
                            continue;
                        }

                        $userId = $revision['user_id'] ?? null;
                        $user = $userId ? User::find($userId) : null;
                        $entries[] = new AuditEntry(
                            'full_day_absence',
                            'Justificación de jornada completa',
                            $createdAt,
                            $absence->company_id,
                            $absence->company?->name ?? 'Desconocida',
                            $userId,
                            $user?->email,
                            $this->describeFullDayAbsenceRevision($absence, $revision),
                            [
                                'justified_absence_id' => $absence->id,
                                'employee_id' => $absence->employee_id,
                                'action' => $revision['action'] ?? 'justify_full_day_absence',
                                'old_values' => $revision['old_values'] ?? null,
                                'new_values' => $revision['new_values'] ?? null,
                            ],
                        );
                    }
                });
        }

        if (in_array('payroll_state', $types, true)) {
            PayPeriod::withoutCompanyScope()
                ->with('company')
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->whereNotNull('metadata')
                ->limit(500)
                ->get()
                ->each(function (PayPeriod $payPeriod) use (&$entries): void {
                    $metadata = $payPeriod->metadata ?? [];
                    foreach (['approved', 'exported', 'processed'] as $action) {
                        $at = $metadata[$action.'_at'] ?? null;
                        if (! $at) {
                            continue;
                        }

                        $createdAt = Carbon::parse($at);
                        if ($this->from && $createdAt->lt(Carbon::parse($this->from)->startOfDay())) {
                            continue;
                        }
                        if ($this->to && $createdAt->gt(Carbon::parse($this->to)->endOfDay())) {
                            continue;
                        }

                        $userId = $metadata[$action.'_by'] ?? null;
                        $user = $userId ? User::find($userId) : null;

                        $entries[] = new AuditEntry(
                            'payroll_state',
                            'Estado de nómina',
                            $createdAt,
                            $payPeriod->company_id,
                            $payPeriod->company?->name ?? 'Desconocida',
                            $userId,
                            $user?->email,
                            "Período {$payPeriod->name} cambió a estado {$action}",
                            ['pay_period_id' => $payPeriod->id, 'status' => $action]
                        );
                    }

                    foreach ($metadata['reopenings'] ?? [] as $reopening) {
                        $at = $reopening['at'] ?? null;
                        if (! $at || ! $this->dateIsVisible(Carbon::parse($at))) {
                            continue;
                        }

                        $userId = $reopening['user_id'] ?? null;
                        $user = $userId ? User::find($userId) : null;
                        $invalidated = (int) ($reopening['invalidated_results'] ?? 0);
                        $reason = $reopening['reason'] ?? 'Sin motivo registrado';
                        $entries[] = new AuditEntry(
                            'payroll_state',
                            'Estado de nómina',
                            Carbon::parse($at),
                            $payPeriod->company_id,
                            $payPeriod->company?->name ?? 'Desconocida',
                            $userId,
                            $user?->email,
                            "Período {$payPeriod->name} reabierto de procesado a validación. Motivo: {$reason}. {$invalidated} resultados invalidados",
                            ['pay_period_id' => $payPeriod->id, 'status' => 'reopened']
                        );
                    }
                });
        }

        if (in_array('mark_revision', $types, true)) {
            RawMark::withoutCompanyScope()
                ->with('company')
                ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->whereNotNull('metadata')
                ->limit(500)
                ->get()
                ->each(function (RawMark $rawMark) use (&$entries): void {
                    foreach ($rawMark->metadata['revisions'] ?? [] as $rev) {
                        $at = $rev['at'] ?? null;
                        if (! $at) {
                            continue;
                        }

                        $createdAt = Carbon::parse($at);
                        if ($this->from && $createdAt->lt(Carbon::parse($this->from)->startOfDay())) {
                            continue;
                        }
                        if ($this->to && $createdAt->gt(Carbon::parse($this->to)->endOfDay())) {
                            continue;
                        }

                        $userId = $rev['user_id'] ?? null;
                        $user = $userId ? User::find($userId) : null;

                        $description = $this->describeMarkRevision($rawMark, $rev);
                        $entries[] = new AuditEntry(
                            'mark_revision',
                            'Revisión de marca',
                            $createdAt,
                            $rawMark->company_id,
                            $rawMark->company?->name ?? 'Desconocida',
                            $userId,
                            $user?->email,
                            $description,
                            ['raw_mark_id' => $rawMark->id, 'action' => $rev['action']]
                        );
                    }
                });
        }

        usort($entries, fn (AuditEntry $a, AuditEntry $b) => $b->createdAt <=> $a->createdAt);

        return $entries;
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
            'manual_create' => "Marca manual #{$rawMark->id} (empleado {$rawMark->employee_external_id}) creada para {$revision['work_date']} a {$revision['event_at']}. Motivo: {$reason}",
            'edit_event_at' => "{$prefix}: fecha/hora de {$oldEventAt} a {$newEventAt}. Motivo: {$reason}",
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

    private function dateIsVisible(Carbon $date): bool
    {
        return (! $this->from || $date->gte(Carbon::parse($this->from)->startOfDay()))
            && (! $this->to || $date->lte(Carbon::parse($this->to)->endOfDay()));
    }

    private function applyFilters(array $entries): array
    {
        if ($this->user) {
            $needle = strtolower($this->user);
            $entries = array_filter($entries, fn (AuditEntry $e) => $e->userEmail && str_contains(strtolower($e->userEmail), $needle));
        }

        return array_values($entries);
    }
}
