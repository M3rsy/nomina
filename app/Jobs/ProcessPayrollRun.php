<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollProcessor;
use App\Services\Payroll\PayrollRunTelemetryRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

final class ProcessPayrollRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $backoff = 10;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("payroll-run:{$this->runId}"))
            ->releaseAfter($this->timeout + 60)
            ->expireAfter($this->timeout + 60)];
    }

    public function handle(PayrollProcessor $processor): void
    {
        $telemetry = app(PayrollRunTelemetryRecorder::class);
        $identity = $this->identity();

        if ($identity === null || ! $this->begin($identity, $telemetry)) {
            return;
        }

        try {
            $period = PayPeriod::withoutCompanyScope()
                ->where('company_id', $identity->company_id)
                ->findOrFail($identity->pay_period_id);
            $processor->processPayPeriod($period);
            $this->complete($identity, $telemetry);
        } catch (Throwable $exception) {
            $this->rememberFailure($identity, $exception);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $identity = $this->identity();

        if ($identity === null) {
            return;
        }

        DB::transaction(function () use ($identity, $exception): void {
            [, $run] = $this->lockedContext($identity);

            if ($run->isActive()) {
                $run->markFailed($run->last_error ?: $this->errorMessage($exception));
                app(PayrollRunTelemetryRecorder::class)->failed($run, $exception);
            }
        });
    }

    private function identity(): ?stdClass
    {
        return PayrollRun::withoutCompanyScope()
            ->select(['company_id', 'pay_period_id'])
            ->whereKey($this->runId)
            ->toBase()
            ->first();
    }

    private function begin(stdClass $identity, PayrollRunTelemetryRecorder $telemetry): bool
    {
        return DB::transaction(function () use ($identity, $telemetry): bool {
            [$period, $run] = $this->lockedContext($identity);

            if (! $run->isActive()) {
                return false;
            }

            if ($period->trashed()) {
                $run->markFailed('Pay period is no longer available.');
                $telemetry->failedWithCode($run, 'period_unavailable');

                return false;
            }

            if ($run->status === PayrollRun::PROCESSING && $period->status === 'processed') {
                $run->markCompleted();
                $telemetry->completed($run);

                return false;
            }

            if ($run->status === PayrollRun::QUEUED) {
                $run->markProcessing();
                $telemetry->started($run);
            }

            return true;
        });
    }

    private function complete(stdClass $identity, PayrollRunTelemetryRecorder $telemetry): void
    {
        DB::transaction(function () use ($identity, $telemetry): void {
            [, $run] = $this->lockedContext($identity);
            $run->markCompleted();
            $telemetry->completed($run);
        });
    }

    private function rememberFailure(stdClass $identity, Throwable $exception): void
    {
        DB::transaction(function () use ($identity, $exception): void {
            [, $run] = $this->lockedContext($identity);

            if ($run->isActive()) {
                $run->update(['last_error' => $this->errorMessage($exception)]);
            }
        });
    }

    private function lockedContext(stdClass $identity): array
    {
        Company::query()->whereKey($identity->company_id)->lockForUpdate()->firstOrFail();
        $period = PayPeriod::withoutCompanyScope()->withTrashed()->where('company_id', $identity->company_id)
            ->lockForUpdate()->findOrFail($identity->pay_period_id);

        $run = PayrollRun::withoutCompanyScope()
            ->where('company_id', $identity->company_id)
            ->where('pay_period_id', $identity->pay_period_id)
            ->lockForUpdate()
            ->findOrFail($this->runId);

        return [$period, $run];
    }

    private function errorMessage(Throwable $exception): string
    {
        return Str::limit($exception->getMessage() ?: class_basename($exception), 2000, '');
    }
}
