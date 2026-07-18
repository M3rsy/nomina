<?php

namespace App\Services;

use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Services\Parsers\RawMarkPayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FileValidator
{
    /**
     * @param  Collection<int, RawMarkPayload>  $records
     */
    public function validate(UploadedFile $uploadedFile, Collection $records): ValidationReport
    {
        DB::transaction(function () use ($uploadedFile, $records) {
            $this->insertRecords($uploadedFile, $records);
            $this->runValidation($uploadedFile, $records);
        });

        return $this->buildReport($uploadedFile);
    }

    private function insertRecords(UploadedFile $uploadedFile, Collection $records): void
    {
        $payPeriod = $uploadedFile->payPeriod;
        $companyId = $uploadedFile->company_id;

        foreach ($records as $record) {
            RawMark::create([
                'company_id' => $companyId,
                'pay_period_id' => $payPeriod->id,
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

    private function runValidation(UploadedFile $uploadedFile, Collection $records): void
    {
        $payPeriod = $uploadedFile->payPeriod;
        $companyId = $uploadedFile->company_id;

        $employeeIds = DB::table('employees')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('id', 'external_id')
            ->toArray();

        $existingMarks = RawMark::query()
            ->where('company_id', $companyId)
            ->where('pay_period_id', $payPeriod->id)
            ->where('uploaded_file_id', '!=', $uploadedFile->id)
            ->get(['employee_external_id', 'event_at'])
            ->map(fn ($mark) => $mark->employee_external_id.'|'.$mark->event_at->toDateTimeString())
            ->toArray();

        $seen = [];
        $validCount = 0;
        $issueCount = 0;

        foreach ($records as $record) {
            $status = 'valid';
            $notes = null;

            $employeeId = $employeeIds[$record->employee_external_id] ?? null;
            if ($employeeId === null) {
                $status = 'unknown_employee';
                $notes = 'Empleado no encontrado';
            }

            if ($status === 'valid' && ($record->event_at->lt($payPeriod->start_date) || $record->event_at->gt($payPeriod->end_date))) {
                $status = 'out_of_period';
                $notes = 'Fuera del período';
            }

            $key = $record->employee_external_id.'|'.$record->event_at->toDateTimeString();
            if ($status === 'valid' && (isset($seen[$key]) || in_array($key, $existingMarks, true))) {
                $status = 'duplicate';
                $notes = 'Duplicado';
            }
            $seen[$key] = true;

            if ($status === 'valid') {
                $validCount++;
            } else {
                $issueCount++;
            }

            RawMark::query()
                ->where('uploaded_file_id', $uploadedFile->id)
                ->where('row_number', $record->row_number)
                ->update([
                    'status' => $status,
                    'notes' => $notes,
                    'employee_id' => $employeeId,
                ]);
        }

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
