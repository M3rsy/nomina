<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Employees\EmployeeBulkImportService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeImportController extends Controller
{
    public function template(EmployeeBulkImportService $service): StreamedResponse
    {
        Gate::authorize('create', Employee::class);

        return response()->streamDownload(
            fn () => $service->writeTemplate(),
            'plantilla-empleados.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
