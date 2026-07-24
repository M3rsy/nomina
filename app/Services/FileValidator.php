<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\Parsers\RawMarkPayload;
use App\Services\Payroll\LockedPayrollContext;
use App\Services\Payroll\PayrollContextLocker;
use App\Services\Payroll\PayrollContextTargets;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FileValidator
{
    public function __construct(
        private ShiftOccurrenceResolver $shiftOccurrenceResolver,
        private AttendanceFactGenerationTracker $factGenerations,
        private PayrollContextLocker $contextLocker,
    ) {}

    /**
     * @param  Collection<int, RawMarkPayload>  $records
     */
    public function validate(UploadedFile $uploadedFile, Collection $records): ValidationReport
    {
        $this->contextLocker->within(
            $uploadedFile->company_id,
            fn (): PayrollContextTargets => $this->resolveTargets($uploadedFile, $records),
            function (LockedPayrollContext $context) use ($uploadedFile, $records): void {
                $this->insertRecords($uploadedFile, $records);
                $this->runValidation($uploadedFile, $records, $context);
            },
        );

        return $this->buildReport($uploadedFile);
    }

    private function insertRecords(UploadedFile $uploadedFile, Collection $records): void
    {
        $companyId = $uploadedFile->company_id;

        foreach ($records as $record) {
            RawMark::create([
                'company_id' => $companyId,
                'pay_period_id' => $uploadedFile->pay_period_id,
                'uploaded_file_id' => $uploadedFile->id,
                'employee_external_id' => $record->employee_external_id,
                'employee_id' => null,
                'event_at' => $record->event_at,
                'raw_line' => $record->raw_line,
                'source' => $record->source,
                'row_number' => $record->row_number,
                'status' => 'pending',
                'notes' => null,
                'metadata' => $record->metadata,
            ]);
        }
    }

    private function runValidation(
        UploadedFile $uploadedFile,
        Collection $records,
        LockedPayrollContext $context,
    ): void {
        $companyId = $uploadedFile->company_id;
        $payPeriod = $context->payPeriod($uploadedFile->pay_period_id);
        $employees = $context->employees->keyBy('external_id');

        $existingMarks = RawMark::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('uploaded_file_id', '!=', $uploadedFile->id)
            ->whereIn('status', ['valid', 'corrected'])
            ->get(['employee_external_id', 'event_at'])
            ->map(fn ($mark) => $mark->employee_external_id.'|'.$mark->event_at->toDateTimeString())
            ->toArray();

        $seen = [];
        $validCount = 0;
        $issueCount = 0;
        $generationAdvances = collect();

        foreach ($records as $record) {
            $status = 'valid';
            $notes = null;
            $workDate = null;
            $workDateIsLocked = false;

            $employee = $employees->get($record->employee_external_id);
            if ($employee === null) {
                $status = 'unknown_employee';
                $notes = 'Empleado no encontrado';
            }

            if ($status === 'valid') {
                $workDate = $this->shiftOccurrenceResolver->workDateFor($employee, $record->event_at);
                $workDateIsLocked = $this->workDateIsLocked($context->payPeriods, $workDate);

                if (! $workDateIsLocked && ($workDate->lt($payPeriod->start_date) || $workDate->gt($payPeriod->end_date))) {
                    $status = 'out_of_period';
                    $notes = 'Fuera del período';
                }
            }

            $key = $record->employee_external_id.'|'.$record->event_at->toDateTimeString();
            if ($status === 'valid' && (isset($seen[$key]) || in_array($key, $existingMarks, true))) {
                $status = 'duplicate';
                $notes = 'Duplicado';
            }
            $seen[$key] = true;

            if ($status === 'valid' && $workDateIsLocked) {
                $status = 'invalid';
                $notes = 'La fecha laboral pertenece a un período bloqueado.';
            }

            $markQuery = RawMark::query()
                ->where('uploaded_file_id', $uploadedFile->id)
                ->where('row_number', $record->row_number);
            $markQuery->update([
                'status' => $status,
                'notes' => $notes,
                'employee_id' => $employee?->id,
            ]);

            if ($status === 'valid') {
                $occurrence = $this->shiftOccurrenceResolver->resolve($employee, $workDate);

                if (! $occurrence->satisfiesManualPairInvariant()) {
                    $status = 'invalid';
                    $notes = 'La importación rompería un par con una marca manual auditada.';
                    $markQuery->update(['status' => $status, 'notes' => $notes]);
                }
            }

            if ($status === 'valid') {
                $validCount++;
                $generationAdvances->push(['employee' => $employee, 'work_date' => $workDate]);
            } else {
                $issueCount++;
            }
        }

        $generationAdvances
            ->groupBy(fn (array $advance): string => sprintf(
                '%020d|%s',
                $advance['employee']->id,
                $advance['work_date']->toDateString(),
            ))
            ->sortKeys()
            ->each(function (Collection $advances): void {
                $advance = $advances->first();
                $this->factGenerations->advanceBy(
                    $advance['employee'],
                    $advance['work_date'],
                    $advances->count(),
                );
            });

        $uploadedFile->status = $this->computeFileStatus($validCount, $issueCount);
        $uploadedFile->validation_summary = [
            'total' => $records->count(),
            'valid' => $validCount,
            'duplicate' => $this->countStatus($uploadedFile, 'duplicate'),
            'out_of_period' => $this->countStatus($uploadedFile, 'out_of_period'),
            'unknown_employee' => $this->countStatus($uploadedFile, 'unknown_employee'),
            'invalid_row' => $this->countStatus($uploadedFile, 'invalid'),
        ];
        $uploadedFile->save();
    }

    /** @param Collection<int, PayPeriod> $periods */
    private function workDateIsLocked(Collection $periods, CarbonInterface $workDate): bool
    {
        return $periods->contains(fn (PayPeriod $period): bool => $period->start_date->lte($workDate)
            && $period->end_date->gte($workDate)
            && in_array(
                $period->status,
                PayPeriod::ATTENDANCE_LOCKED_STATUSES,
                true,
            ));
    }

    /** @param Collection<int, RawMarkPayload> $records */
    private function resolveTargets(UploadedFile $uploadedFile, Collection $records): PayrollContextTargets
    {
        $employees = Employee::withoutCompanyScope()
            ->where('company_id', $uploadedFile->company_id)
            ->whereNull('deleted_at')
            ->whereIn('external_id', $records->map->employee_external_id->unique())
            ->orderBy('id')
            ->get()
            ->keyBy('external_id');
        $workDates = $records->map(function (RawMarkPayload $record) use ($employees): ?string {
            $employee = $employees->get($record->employee_external_id);

            return $employee === null
                ? null
                : $this->shiftOccurrenceResolver->workDateFor($employee, $record->event_at)->toDateString();
        })->filter()->unique()->sort()->values();
        $periodIds = PayPeriod::withoutCompanyScope()
            ->where('company_id', $uploadedFile->company_id)
            ->where(function ($query) use ($uploadedFile, $workDates): void {
                $query->whereKey($uploadedFile->pay_period_id);

                foreach ($workDates as $workDate) {
                    $query->orWhere(function ($overlap) use ($workDate): void {
                        $overlap->whereDate('start_date', '<=', $workDate)
                            ->whereDate('end_date', '>=', $workDate);
                    });
                }
            })
            ->pluck('id')
            ->all();

        return new PayrollContextTargets($periodIds, $employees->pluck('id')->all());
    }

    private function computeFileStatus(int $validCount, int $issueCount): string
    {
        if ($validCount === 0) {
            return 'invalid';
        }

        if ($issueCount > 0) {
            return 'valid_with_warnings';
        }

        return 'valid';
    }

    private function countStatus(UploadedFile $uploadedFile, string $status): int
    {
        return RawMark::query()
            ->where('uploaded_file_id', $uploadedFile->id)
            ->where('status', $status)
            ->count();
    }

    private function buildReport(UploadedFile $uploadedFile): ValidationReport
    {
        $summary = $uploadedFile->validation_summary ?? [];
        $counts = [
            'total' => $summary['total'] ?? 0,
            'valid' => $summary['valid'] ?? 0,
            'duplicate' => $summary['duplicate'] ?? 0,
            'out_of_period' => $summary['out_of_period'] ?? 0,
            'unknown_employee' => $summary['unknown_employee'] ?? 0,
            'invalid_row' => $summary['invalid_row'] ?? 0,
        ];

        $issues = RawMark::query()
            ->where('uploaded_file_id', $uploadedFile->id)
            ->whereIn('status', ['duplicate', 'out_of_period', 'unknown_employee', 'invalid'])
            ->get(['row_number', 'employee_external_id', 'event_at', 'status', 'notes'])
            ->map(fn ($mark) => [
                'row_number' => $mark->row_number,
                'employee_external_id' => $mark->employee_external_id,
                'event_at' => $mark->event_at->toDateTimeString(),
                'status' => $mark->status,
                'notes' => $mark->notes,
            ])
            ->toArray();

        $rowStatuses = RawMark::query()
            ->where('uploaded_file_id', $uploadedFile->id)
            ->pluck('status', 'row_number')
            ->toArray();

        return new ValidationReport(counts: $counts, issues: $issues, rowStatuses: $rowStatuses);
    }
}
