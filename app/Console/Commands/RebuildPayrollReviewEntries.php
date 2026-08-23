<?php

namespace App\Console\Commands;

use App\Models\PayPeriod;
use App\Services\Payroll\PayrollReviewProjection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RebuildPayrollReviewEntries extends Command
{
    protected $signature = 'payroll:review:rebuild {pay_period_id? : Pay period id to rebuild} {--company-id= : Rebuild all periods for one company id}';

    protected $description = 'Rebuild the discardable payroll_review_entries read projection for payroll review screens.';

    public function handle(PayrollReviewProjection $projection): int
    {
        if (! Schema::hasTable('payroll_review_entries')) {
            $this->error('The payroll_review_entries table does not exist. Run migrations before rebuilding.');

            return self::FAILURE;
        }

        $periodId = $this->argument('pay_period_id');
        $companyId = $this->option('company-id') !== null ? (int) $this->option('company-id') : null;
        $periods = PayPeriod::withoutCompanyScope()
            ->when($periodId !== null, fn ($query) => $query->whereKey((int) $periodId))
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->get();

        if ($periods->isEmpty()) {
            $this->warn('No pay periods matched the rebuild filters.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($periods as $period) {
            $count = $projection->rebuild($period);
            $total += $count;
            $this->line("Rebuilt period {$period->id}: {$count} review entries.");
        }

        $this->info("Rebuilt {$total} payroll review entries.");

        return self::SUCCESS;
    }
}
