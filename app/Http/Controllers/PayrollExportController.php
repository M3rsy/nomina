<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Services\Payroll\PayrollExcelExporter;
use App\Services\Payroll\PayrollStubExporter;
use App\Services\Payroll\TemporaryXlsxFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollExportController extends Controller
{
    public function __construct(
        private PayrollExcelExporter $excelExporter,
        private PayrollStubExporter $stubExporter,
    ) {}

    public function __invoke(PayPeriod $payPeriod): BinaryFileResponse
    {
        Gate::authorize('payroll.export');
        Gate::authorize('view', $payPeriod);

        $path = null;

        try {
            [$path, $filename] = DB::transaction(function () use ($payPeriod, &$path): array {
                $lockedPeriod = $this->lockPeriodInState($payPeriod, ['approved', 'exported']);
                $path = $this->excelExporter->export($lockedPeriod);
                $filename = $this->excelExporter->filename($lockedPeriod);

                if ($lockedPeriod->status === 'approved') {
                    $lockedPeriod->update(['status' => 'exported']);
                }

                return [$path, $filename];
            });
        } catch (\Throwable $throwable) {
            if ($path !== null) {
                TemporaryXlsxFile::delete($path);
            }

            throw $throwable;
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function stub(PayPeriod $payPeriod, string $empleado): BinaryFileResponse
    {
        Gate::authorize('payroll.export');
        Gate::authorize('view', $payPeriod);

        $employee = Employee::withoutCompanyScope()->findOrFail((int) $empleado);

        if ($employee->company_id !== $payPeriod->company_id) {
            abort(403);
        }

        [$path, $filename] = DB::transaction(function () use ($payPeriod, $employee): array {
            $lockedPeriod = $this->lockPeriodInState($payPeriod, ['exported']);

            return [
                $this->stubExporter->export($lockedPeriod, $employee),
                $this->stubExporter->filename($lockedPeriod, $employee),
            ];
        });

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @param  list<string>  $allowedStatuses
     */
    private function lockPeriodInState(PayPeriod $payPeriod, array $allowedStatuses): PayPeriod
    {
        $lockedPeriod = PayPeriod::withoutCompanyScope()
            ->lockForUpdate()
            ->findOrFail($payPeriod->id);

        if (! in_array($lockedPeriod->status, $allowedStatuses, true)) {
            abort(Response::HTTP_CONFLICT, 'El estado actual del período no permite esta exportación.');
        }

        return $lockedPeriod;
    }
}
