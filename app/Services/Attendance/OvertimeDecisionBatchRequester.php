<?php

namespace App\Services\Attendance;

use App\Jobs\ProcessOvertimeDecisionBatch;
use App\Models\Company;
use App\Models\OvertimeDecision;
use App\Models\OvertimeDecisionBatch;
use App\Models\PayPeriod;
use App\Models\User;
use App\Services\Payroll\OvertimeReviewReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OvertimeDecisionBatchRequester
{
    private const MAX_TARGETS = 500;

    public function __construct(private OvertimeReviewReader $reviews) {}

    public function request(
        PayPeriod $period,
        OvertimeDecisionBatchRequest $request,
        User $actor,
    ): OvertimeDecisionBatch {
        $period = PayPeriod::withoutCompanyScope()->with('company')->findOrFail($period->id);
        $actor = User::query()->findOrFail($actor->id);
        $this->authorize($actor, $period->company);
        $this->validateRequest($request);
        $payloadHash = $this->payloadHash($period, $actor, $request);
        if ($existing = $this->existing($request->requestKey, $payloadHash)) {
            return $this->recover($existing);
        }

        try {
            $batch = DB::transaction(function () use ($period, $actor, $request, $payloadHash): OvertimeDecisionBatch {
                $period = PayPeriod::withoutCompanyScope()->with('company')->lockForUpdate()->findOrFail($period->id);
                $this->authorize($actor = User::query()->findOrFail($actor->id), $period->company);
                $this->validatePeriod($period);

                $targets = $this->reviews->pendingTargetsForPeriod(
                    $period,
                    $request->uploadedFileId,
                    $request->filters,
                );
                if (! $request->all) {
                    $targets = $targets->only($request->selectedTokens);
                    if ($targets->count() !== count($request->selectedTokens)) {
                        throw ValidationException::withMessages([
                            'selection' => 'La selección cambió. Revísela antes de continuar.',
                        ]);
                    }
                }
                if ($targets->count() > self::MAX_TARGETS) {
                    throw ValidationException::withMessages([
                        'selection' => 'Hay más de 500 candidatos pendientes. Aplique filtros más específicos antes de continuar.',
                    ]);
                }
                if ($targets->isEmpty() || ! hash_equals(
                    $request->expectedSelectionHash,
                    $this->selectionHash($targets, $request->filters, $request->all),
                )) {
                    throw ValidationException::withMessages([
                        'selection' => 'La selección cambió. Revísela antes de continuar.',
                    ]);
                }

                $items = $targets->values()->map(fn (array $target): array => [
                    'employee_id' => $target['employee_id'],
                    'work_date' => $target['work_date'],
                    'candidate_key' => $target['candidate_key'],
                    'fingerprint' => $target['fingerprint'],
                ]);
                $batch = OvertimeDecisionBatch::withoutCompanyScope()->create([
                    'request_key' => $request->requestKey, 'payload_hash' => $payloadHash,
                    'company_id' => $period->company_id, 'pay_period_id' => $period->id,
                    'requested_by' => $actor->id, 'decision' => $request->decision, 'reason' => $request->reason,
                    'status' => OvertimeDecisionBatch::QUEUED, 'total_items' => $items->count(),
                ]);
                $timestamp = now();
                DB::table('overtime_decision_batch_items')->insert($items->map(fn (array $item): array => [
                    'batch_id' => $batch->id,
                    'employee_id' => $item['employee_id'],
                    'work_date' => $item['work_date'],
                    'candidate_key' => $item['candidate_key'],
                    'fingerprint' => $item['fingerprint'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all());
                DB::afterCommit(fn () => ProcessOvertimeDecisionBatch::dispatch($batch->id));

                return $batch;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return ($existing = $this->existing($request->requestKey, $payloadHash))
                ? $this->recover($existing) : throw $exception;
        }

        return $batch->load('items');
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

    private function validateRequest(OvertimeDecisionBatchRequest $request): void
    {
        $filters = $request->filters;
        $validFilterShape = count($filters) === 4
            && array_diff(array_keys($filters), ['search', 'status', 'date', 'rate']) === []
            && is_string($filters['search'] ?? null) && mb_strlen($filters['search']) <= 255
            && is_string($filters['status'] ?? null) && in_array($filters['status'], ['pending', 'approved', 'rejected', 'all'], true)
            && is_string($filters['date'] ?? null) && ($filters['date'] === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters['date']))
            && is_string($filters['rate'] ?? null) && in_array($filters['rate'], ['', 'ordinary', 'extra25', 'extra50', 'extra75', 'extra100'], true);
        $validTokens = collect($request->selectedTokens)->every(
            fn (mixed $token): bool => is_string($token)
                && preg_match('/^\d+\|\d{4}-\d{2}-\d{2}\|[a-f0-9]{64}$/D', $token),
        );

        if (! in_array($request->decision, [OvertimeDecision::APPROVED, OvertimeDecision::REJECTED], true)
            || $request->reason === '' || mb_strlen($request->reason) > 500
            || ! Str::isUuid($request->requestKey)
            || ($request->uploadedFileId !== null && $request->uploadedFileId < 1)
            || ! $validFilterShape || ! $validTokens
            || ! preg_match('/^[a-f0-9]{64}$/D', $request->expectedSelectionHash)) {
            throw ValidationException::withMessages(['request' => 'La solicitud de decisiones no es válida.']);
        }
    }

    private function payloadHash(PayPeriod $period, User $actor, OvertimeDecisionBatchRequest $request): string
    {
        return hash('sha256', json_encode([
            'pay_period_id' => $period->id,
            'actor_id' => $actor->id,
            'decision' => $request->decision,
            'reason' => $request->reason,
            'uploaded_file_id' => $request->uploadedFileId,
            'filters' => $request->filters,
            'all' => $request->all,
            'selected' => $request->selectedTokens,
            'selection' => $request->expectedSelectionHash,
        ], JSON_THROW_ON_ERROR));
    }

    private function existing(string $key, string $payloadHash): ?OvertimeDecisionBatch
    {
        $batch = OvertimeDecisionBatch::withoutCompanyScope()->where('request_key', $key)->first();
        if ($batch !== null && ! hash_equals($batch->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La clave de solicitud ya fue usada con otros datos.']);
        }

        return $batch?->load('items');
    }

    /**
     * @param  Collection<string, array{employee_id:int,work_date:string,candidate_key:string,fingerprint:string}>  $targets
     * @param  array{search:string,status:string,date:string,rate:string}  $filters
     */
    private function selectionHash(Collection $targets, array $filters, bool $all): string
    {
        return hash('sha256', json_encode([
            'filters' => $filters,
            'all' => $all,
            'candidates' => $targets
                ->map(fn (array $target, string $token): string => $token.'|'.$target['fingerprint'])
                ->sort()
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
