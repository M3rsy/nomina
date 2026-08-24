<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\PayrollRunTelemetry;
use Illuminate\Queue\TimeoutExceededException;
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

    public function completed(PayrollRun $run): void
    {
        $this->append($run, PayrollRunTelemetry::COMPLETED);
    }

    public function failed(PayrollRun $run, Throwable $exception): void
    {
        $this->append($run, PayrollRunTelemetry::FAILED, $this->failureCode($exception));
    }

    public function failedWithCode(PayrollRun $run, string $code): void
    {
        $this->append($run, PayrollRunTelemetry::FAILED, $code);
    }

    private function append(PayrollRun $run, string $event, ?string $code = null, ?int $previousRunId = null): void
    {
        PayrollRunTelemetry::create([
            'payroll_run_id' => $run->id,
            'previous_run_id' => $previousRunId,
            'event' => $event,
            'code' => $code,
            'occurred_at' => now(),
        ]);
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
