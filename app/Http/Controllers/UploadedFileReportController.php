<?php

namespace App\Http\Controllers;

use App\Models\UploadedFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadedFileReportController extends Controller
{
    public function download(UploadedFile $uploadedFile): StreamedResponse
    {
        Gate::authorize('view', $uploadedFile);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="reporte-'.$uploadedFile->id.'.csv"',
        ];

        $records = $uploadedFile->rawMarks()
            ->whereIn('status', ['duplicate', 'out_of_period', 'unknown_employee', 'invalid'])
            ->orderBy('row_number')
            ->get();

        return response()->stream(function () use ($records) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['row_number', 'employee_external_id', 'event_at', 'source', 'status', 'notes']);

            foreach ($records as $record) {
                fputcsv($handle, [
                    $record->row_number,
                    $record->employee_external_id,
                    $record->event_at->toDateTimeString(),
                    $record->source,
                    $record->status,
                    $record->notes,
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
