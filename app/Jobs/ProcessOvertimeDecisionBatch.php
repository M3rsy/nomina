<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\OvertimeDecisionBatch;
use App\Models\OvertimeDecisionBatchItem;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\Attendance\OvertimeDecisionRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessOvertimeDecisionBatch implements ShouldQueue
{
    use Queueable;

    private const CHUNK_SIZE = 20;

    public int $tries = 30;

    public int $backoff = 10;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public int $batchId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("overtime-decision-batch:{$this->batchId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(OvertimeDecisionRecorder $recorder): void
    {
        for ($processed = 0; $processed < self::CHUNK_SIZE; $processed++) {
            $item = $this->claim();
            if ($item === null) {
                break;
            }

            try {
                $batch = OvertimeDecisionBatch::withoutCompanyScope()->findOrFail($this->batchId);
                $actor = $batch->requested_by === null ? null : User::query()->find($batch->requested_by);
                if ($actor === null) {
                    throw new AuthorizationException('El solicitante del lote ya no está disponible.');
                }
                $recorder->decide(
                    PayPeriod::withoutCompanyScope()->findOrFail($batch->pay_period_id),
                    Employee::withoutCompanyScope()->findOrFail($item->employee_id),
                    $item->work_date,
                    $item->candidate_key,
                    $batch->decision,
                    $batch->reason,
                    $actor,
                    $item->id,
                );
                $this->finishItem($item->id, OvertimeDecisionBatchItem::SUCCEEDED);
            } catch (ValidationException|AuthorizationException $exception) {
                $this->finishItem($item->id, OvertimeDecisionBatchItem::FAILED, $exception->getMessage());
            } catch (Throwable $exception) {
                OvertimeDecisionBatch::withoutCompanyScope()->whereKey($this->batchId)
                    ->update(['last_error' => $exception->getMessage()]);
                throw $exception;
            }
        }

        $this->finishOrRelease();
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $batch = OvertimeDecisionBatch::withoutCompanyScope()->lockForUpdate()->find($this->batchId);
            if ($batch === null || ! in_array($batch->status, [
                OvertimeDecisionBatch::QUEUED,
                OvertimeDecisionBatch::PROCESSING,
            ], true)) {
                return;
            }
            if ($batch->status === OvertimeDecisionBatch::PROCESSING
                && $exception::class === MaxAttemptsExceededException::class) {
                return;
            }
            $batch->items()->where('status', OvertimeDecisionBatchItem::PROCESSING)
                ->update(['status' => OvertimeDecisionBatchItem::PENDING, 'last_error' => $exception->getMessage()]);
            $batch->update(['status' => 'failed', 'finished_at' => now(), 'last_error' => $exception->getMessage()]);
        });
    }

    private function claim(): ?OvertimeDecisionBatchItem
    {
        return DB::transaction(function (): ?OvertimeDecisionBatchItem {
            $batch = OvertimeDecisionBatch::withoutCompanyScope()->lockForUpdate()->find($this->batchId);
            if ($batch === null || in_array($batch->status, [OvertimeDecisionBatch::COMPLETED, OvertimeDecisionBatch::COMPLETED_WITH_ERRORS, 'failed'], true)) {
                return null;
            }
            $batch->update([
                'status' => OvertimeDecisionBatch::PROCESSING,
                'started_at' => $batch->started_at ?? now(),
                'last_error' => null,
            ]);
            $item = $batch->items()->whereIn('status', [
                OvertimeDecisionBatchItem::PROCESSING, OvertimeDecisionBatchItem::PENDING,
            ])->orderByRaw("case when status = 'processing' then 0 else 1 end")->orderBy('id')->lockForUpdate()->first();
            if ($item !== null) {
                $item->update([
                    'status' => OvertimeDecisionBatchItem::PROCESSING,
                    'attempts' => $item->attempts + 1,
                    'last_error' => null,
                ]);
            }

            return $item;
        });
    }

    private function finishItem(int $itemId, string $status, ?string $error = null): void
    {
        OvertimeDecisionBatchItem::query()->whereKey($itemId)->update([
            'status' => $status,
            'last_error' => $error,
        ]);
    }

    private function finishOrRelease(): void
    {
        DB::transaction(function (): void {
            $batch = OvertimeDecisionBatch::withoutCompanyScope()->lockForUpdate()->find($this->batchId);
            if ($batch === null || in_array($batch->status, [OvertimeDecisionBatch::COMPLETED, OvertimeDecisionBatch::COMPLETED_WITH_ERRORS, 'failed'], true)) {
                return;
            }
            if ($batch->items()->whereIn('status', [OvertimeDecisionBatchItem::PENDING, OvertimeDecisionBatchItem::PROCESSING])->exists()) {
                DB::afterCommit(fn () => $this->release($this->backoff));

                return;
            }
            $batch->update([
                'status' => $batch->items()->where('status', OvertimeDecisionBatchItem::FAILED)->exists()
                    ? OvertimeDecisionBatch::COMPLETED_WITH_ERRORS
                    : OvertimeDecisionBatch::COMPLETED,
                'finished_at' => now(),
                'last_error' => null,
            ]);
        });
    }
}
