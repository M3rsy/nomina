<?php

namespace App\Services\Attendance;

use App\Jobs\ProcessOvertimeDecisionBatch;
use App\Models\Company;
use App\Models\OvertimeDecision;
use App\Models\OvertimeDecisionBatch;
use App\Models\PayPeriod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OvertimeDecisionBatchRequester
{
    public function __construct(private PayrollPeriodReviewSnapshot $snapshot) {}

    public function request(
        PayPeriod $period,
        array $targets,
        string $decision,
        string $reason,
        User $actor,
        string $idempotencyKey,
    ): OvertimeDecisionBatch {
        $period = PayPeriod::withoutCompanyScope()->with('company')->findOrFail($period->id);
        $actor = User::query()->findOrFail($actor->id);
        $this->authorize($actor, $period->company);
        $reason = trim($reason);
        $canonical = $this->canonicalTargets($targets, $decision, $reason, $idempotencyKey);
        $payloadHash = hash('sha256', json_encode([
            $period->id, $actor->id, $decision, $reason, $canonical->all(),
        ], JSON_THROW_ON_ERROR));
        if ($existing = $this->existing($idempotencyKey, $payloadHash)) {
            return $this->recover($existing);
        }

        try {
            return DB::transaction(function () use (
                $period, $actor, $decision, $reason, $idempotencyKey, $canonical, $payloadHash,
            ): OvertimeDecisionBatch {
                $period = PayPeriod::withoutCompanyScope()->with('company')->lockForUpdate()->findOrFail($period->id);
                $this->authorize($actor = User::query()->findOrFail($actor->id), $period->company);
                $this->validatePeriod($period);
                $pending = $this->snapshot->forPeriod($period)['reviews']->flatMap(
                    fn (PayrollShiftReview $review) => $review->analysis->overtimeCandidates
                        ->filter(fn (AttendanceSegment $candidate): bool => $review->decisionFor($candidate) === null)
                        ->mapWithKeys(fn (AttendanceSegment $candidate): array => [
                            $this->targetKey($review->employee->id, $review->occurrence->workDate->toDateString(), $candidate->key) => $candidate,
                        ]),
                );
                $items = $canonical->map(function (array $target) use ($pending): array {
                    $candidate = $pending->get($this->targetKey(
                        $target['employee_id'], $target['work_date'], $target['candidate_key'],
                    ));
                    if ($candidate === null) {
                        throw ValidationException::withMessages(['targets' => 'Uno o más candidatos ya no están pendientes o no existen.']);
                    }

                    return [...$target, 'fingerprint' => $candidate->fingerprint];
                });
                $batch = OvertimeDecisionBatch::withoutCompanyScope()->create([
                    'request_key' => $idempotencyKey, 'payload_hash' => $payloadHash,
                    'company_id' => $period->company_id, 'pay_period_id' => $period->id,
                    'requested_by' => $actor->id, 'decision' => $decision, 'reason' => $reason,
                    'status' => OvertimeDecisionBatch::QUEUED, 'total_items' => $items->count(),
                ]);
                $batch->items()->createMany($items->all());
                DB::afterCommit(fn () => ProcessOvertimeDecisionBatch::dispatch($batch->id));

                return $batch->load('items');
            });
        } catch (UniqueConstraintViolationException $exception) {
            return ($existing = $this->existing($idempotencyKey, $payloadHash))
                ? $this->recover($existing) : throw $exception;
        }
    }

    private function recover(OvertimeDecisionBatch $batch): OvertimeDecisionBatch
    {
        return DB::transaction(function () use ($batch): OvertimeDecisionBatch {
            $batch = OvertimeDecisionBatch::withoutCompanyScope()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'failed') {
                $batch->items()->where('status', 'processing')->update(['status' => 'pending', 'last_error' => null]);
                $batch->update(['status' => OvertimeDecisionBatch::QUEUED, 'started_at' => null, 'finished_at' => null, 'last_error' => null]);
            }
            if ($batch->status === OvertimeDecisionBatch::QUEUED) {
                DB::afterCommit(fn () => ProcessOvertimeDecisionBatch::dispatch($batch->id, retryOnOverlap: true));
            }

            return $batch->load('items');
        });
    }

    private function authorize(User $actor, Company $company): void
    {
        if (! $actor->is_active || ! $actor->can('marks.manage')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $company->id)) {
            throw new AuthorizationException('No está autorizado para solicitar decisiones de esta empresa.');
        }
    }

    private function validatePeriod(PayPeriod $period): void
    {
        if ($period->trashed() || in_array($period->status, PayPeriod::ATTENDANCE_LOCKED_STATUSES, true)) {
            throw ValidationException::withMessages(['pay_period' => 'El período no admite decisiones.']);
        }
    }

    private function existing(string $key, string $payloadHash): ?OvertimeDecisionBatch
    {
        $batch = OvertimeDecisionBatch::withoutCompanyScope()->where('request_key', $key)->first();
        if ($batch !== null && ! hash_equals($batch->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La clave de solicitud ya fue usada con otros datos.']);
        }

        return $batch?->load('items');
    }

    private function canonicalTargets(
        array $targets,
        string $decision,
        string $reason,
        string $idempotencyKey,
    ): Collection {
        if (! in_array($decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)
            || $reason === '' || mb_strlen($reason) > 500 || ! Str::isUuid($idempotencyKey)
            || $targets === [] || count($targets) > 500) {
            throw ValidationException::withMessages(['request' => 'La solicitud de decisiones no es válida.']);
        }

        return collect($targets)->map(function (mixed $target): array {
            if (! is_array($target)
                || array_diff(array_keys($target), ['employee_id', 'work_date', 'candidate_key']) !== []
                || count($target) !== 3
                || ! is_int($target['employee_id'] ?? null) || $target['employee_id'] < 1
                || ! is_string($target['work_date'] ?? null)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $target['work_date'])
                || ! is_string($target['candidate_key'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', $target['candidate_key'])) {
                throw ValidationException::withMessages(['targets' => 'Los candidatos seleccionados no son válidos.']);
            }

            return ['employee_id' => $target['employee_id'], 'work_date' => $target['work_date'], 'candidate_key' => $target['candidate_key']];
        })->unique(fn (array $target): string => implode('|', $target))
            ->sortBy(fn (array $target): string => implode('|', $target))->values();
    }

    private function targetKey(int $employeeId, string $workDate, string $candidateKey): string
    {
        return "{$employeeId}|{$workDate}|{$candidateKey}";
    }
}
