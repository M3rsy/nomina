<?php

namespace App\Console\Commands;

use App\Models\AttendanceException;
use App\Models\EmployeeRevision;
use App\Models\EmployeeScheduleAssignment;
use App\Models\JustifiedAbsence;
use App\Models\LoginAttempt;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Services\Auditoria\AuditEntryProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillAuditEntries extends Command
{
    protected $signature = 'audit:backfill {--company-id= : Limit projection to one company id}';

    protected $description = 'Backfill read-only audit_entries projection from auditable source tables.';

    public function handle(AuditEntryProjector $projector): int
    {
        if (! Schema::hasTable('audit_entries')) {
            $this->error('The audit_entries table does not exist. Run migrations before backfilling.');

            return self::FAILURE;
        }

        $companyId = $this->option('company-id') !== null ? (int) $this->option('company-id') : null;
        $count = 0;

        LoginAttempt::query()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->chunkById(500, function ($attempts) use ($projector, &$count): void {
                foreach ($attempts as $attempt) {
                    $projector->project($attempt);
                    $count++;
                }
            });

        EmployeeRevision::query()
            ->when($companyId !== null, fn ($query) => $query->whereHas('employee', fn ($employee) => $employee->withoutGlobalScope('company')->where('company_id', $companyId)))
            ->orderBy('id')
            ->chunkById(500, function ($revisions) use ($projector, &$count): void {
                foreach ($revisions as $revision) {
                    $projector->project($revision);
                    $count++;
                }
            });

        EmployeeScheduleAssignment::withoutCompanyScope()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->chunkById(500, function ($assignments) use ($projector, &$count): void {
                foreach ($assignments as $assignment) {
                    $projector->project($assignment);
                    $count++;
                }
            });

        OvertimeDecision::withoutCompanyScope()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->chunkById(500, function ($decisions) use ($projector, &$count): void {
                foreach ($decisions as $decision) {
                    $projector->project($decision);
                    $count++;
                }
            });

        AttendanceException::withoutCompanyScope()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->chunkById(500, function ($exceptions) use ($projector, &$count): void {
                foreach ($exceptions as $exception) {
                    $projector->project($exception);
                    $count++;
                }
            });

        RawMark::withoutCompanyScope()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(500, function ($rawMarks) use ($projector, &$count): void {
                foreach ($rawMarks as $rawMark) {
                    $count += $projector->projectMetadata($rawMark);
                }
            });

        JustifiedAbsence::withoutCompanyScope()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(500, function ($absences) use ($projector, &$count): void {
                foreach ($absences as $absence) {
                    $count += $projector->projectMetadata($absence);
                }
            });

        PayPeriod::withoutCompanyScope()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(500, function ($payPeriods) use ($projector, &$count): void {
                foreach ($payPeriods as $payPeriod) {
                    $count += $projector->projectMetadata($payPeriod);
                }
            });

        $this->info("Projected {$count} source audit events.");

        return self::SUCCESS;
    }
}
