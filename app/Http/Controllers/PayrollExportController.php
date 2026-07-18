<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Services\Payroll\PayrollExcelExporter;
use App\Services\Payroll\PayrollStubExporter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollExportController extends Controller
{
    public function __construct(
        private PayrollExcelExporter $excelExporter,
        private PayrollStubExporter $stubExporter,
    ) {
    }

    public function __invoke(PayPeriod $payPeriod): BinaryFileResponse
    {
        Gate::authorize('payroll.export');

        $this->ensureCompanyAccess($payPeriod);

        $path = $this->excelExporter->export($payPeriod);
        $filename = $this->excelExporter->filename($payPeriod);

        $this->markExported($payPeriod);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function stub(PayPeriod $payPeriod, string $empleado): BinaryFileResponse
    {
        Gate::authorize('payroll.export');

        $this->ensureCompanyAccess($payPeriod);

        $employee = Employee::withoutCompanyScope()->findOrFail((int) $empleado);

        if ($employee->company_id !== $payPeriod->company_id) {
            abort(403);
        }

        $path = $this->stubExporter->export($payPeriod, $employee);
        $filename = $this->stubExporter->filename($payPeriod, $employee);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function ensureCompanyAccess(PayPeriod $payPeriod): void
    {
        $currentCompany = current_company();

        if ($currentCompany === null) {
            abort(403);
        }

        if ($currentCompany->id !== $payPeriod->company_id && ! auth()->user()?->hasRole('super_admin')) {
            abort(403);
        }
    }

    private function markExported(PayPeriod $payPeriod): void
    {
        if ($payPeriod->status === 'cancelled') {
            return;
        }

        if ($payPeriod->status !== 'exported') {
            $payPeriod->status = 'exported';
            $payPeriod->save();
        }
    }
}
