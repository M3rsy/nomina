<?php

namespace App\Console\Commands;

use App\Models\PayPeriod;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollProcessor;
use App\Services\PayrollRules;
use Illuminate\Console\Command;
class ProcessPayroll extends Command
{
    protected $signature = 'payroll:process {payPeriodId}';

    protected $description = 'Process a ready PayPeriod and persist payroll results.';

    public function handle(PayrollRules $rules): int
    {
        $payPeriod = PayPeriod::findOrFail($this->argument('payPeriodId'));

        app(CurrentCompany::class)->set($payPeriod->company);

        $processor = new PayrollProcessor(new PayrollCalculator(new \App\Services\Payroll\BandSplitter, $rules));
        $report = $processor->processPayPeriod($payPeriod);

        $this->info('Payroll processed successfully.');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Employees processed', $report->employeesProcessed],
                ['Days processed', $report->daysProcessed],
                ['Results inserted', $report->resultsInserted],
                ['Results updated', $report->resultsUpdated],
                ['Justified absences', $report->justifiedAbsenceCount],
                ['Unjustified absences', $report->unjustifiedAbsenceCount],
                ['Missing single marks', $report->missingSingleMarkCount],
            ]
        );

        return self::SUCCESS;
    }
}
