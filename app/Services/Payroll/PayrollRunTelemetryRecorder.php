<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\PayrollRunTelemetry;
use Illuminate\Database\QueryException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PayrollRunTelemetryRecorder
{
    public function queued(PayrollRun $run, ?PayrollRun $previousRun = null): void
    {
        $this->append($run, PayrollRunTelemetry::QUEUED, null, $previousRun?->id);
    }

    public function started(PayrollRun $run): void
    {
        $this->append($run, PayrollRunTelemetry::STARTED);
    }

    /** @param array<string, int> $metrics */
    public function completed(PayrollRun $run, array $metrics = []): void
    {
        $this->append($run, PayrollRunTelemetry::COMPLETED, metrics: $metrics);
    }

    /** @param array<string, int> $metrics */
    public function failed(PayrollRun $run, Throwable $exception, array $metrics = []): void
    {
        $this->append($run, PayrollRunTelemetry::FAILED, $this->failureCode($exception), metrics: $metrics);
    }

    public function failedWithCode(PayrollRun $run, string $code): void
    {
        $this->append($run, PayrollRunTelemetry::FAILED, $code);
    }

    /** @param array<string, int> $metrics */
    private function append(PayrollRun $run, string $event, ?string $code = null, ?int $previousRunId = null, array $metrics = []): void
    {
        DB::afterCommit(function () use ($run, $event, $code, $previousRunId, $metrics): void {
            try {
                PayrollRunTelemetry::create([
                    'payroll_run_id' => $run->id,
                    'previous_run_id' => $previousRunId,
                    'event' => $event,
                    'code' => $code,
                    'occurred_at' => now(),
                    ...$metrics,
                ]);
            } catch (QueryException $exception) {
                $this->reportWriteFailure($run, $event, $exception);
            }
        });
    }

    private function reportWriteFailure(PayrollRun $run, string $event, QueryException $exception): void
    {
        try {
            Log::warning('Payroll run telemetry write failed', [
                'run_id' => $run->id,
                'event' => $event,
                'exception_class' => $exception::class,
            ]);
        } catch (Throwable) {
            // Telemetry failure reporting must not affect payroll processing.
        }
    }

    private function failureCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof PayrollProcessingBlocked => 'attendance_review_blocked',
            $exception instanceof TimeoutExceededException => 'worker_timeout',
            default => 'processing_failed',
        };
    }
}
