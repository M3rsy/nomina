<?php

namespace App\Services\Payroll;

use App\Jobs\ProcessPayrollRun;
use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Attendance\HolidayCalendar;
use App\Services\Attendance\PayrollPeriodReviewSnapshot;
use App\Services\Attendance\PayrollReadinessChecker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class StartPayrollProcessing
{
    public function __construct(
        private HolidayCalendar $holidayCalendar,
        private PayrollPeriodReviewSnapshot $reviewSnapshot,
        private PayrollReadinessChecker $readinessChecker,
    ) {}

    public function start(PayPeriod $period, User $actor, string $requestKey): PayrollRun
    {
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'The request key must be a UUID.']);
        }

        $calendar = $this->holidayCalendar->capture(
            $period->company,
            $period->start_date,
            $period->end_date,
        );

        $created = false;

        try {
            $run = DB::transaction(function () use ($period, $actor, $requestKey, $calendar, &$created): PayrollRun {
                $company = Company::query()->whereKey($period->company_id)->lockForUpdate()->firstOrFail();
                $lockedPeriod = PayPeriod::withoutCompanyScope()
                    ->withTrashed()
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->findOrFail($period->id);
                $lockedActor = User::query()->findOrFail($actor->id);

                $this->authorize($lockedActor, $lockedPeriod);

                $prior = PayrollRun::withoutCompanyScope()->where('request_key', $requestKey)->first();
                if ($prior !== null) {
                    if ($prior->pay_period_id !== $lockedPeriod->id) {
                        throw ValidationException::withMessages(['request_key' => 'The request key belongs to another pay period.']);
                    }

                    return $prior;
                }

                $active = PayrollRun::withoutCompanyScope()
                    ->where('pay_period_id', $lockedPeriod->id)
                    ->where('active_key', true)
                    ->first();
                if ($active !== null) {
                    return $active;
                }

                if ($lockedPeriod->trashed() || ! in_array($lockedPeriod->status, ['draft', 'validating', 'ready'], true)) {
                    throw ValidationException::withMessages([
                        'pay_period' => 'Only a reviewable pay period can be processed.',
                    ]);
                }

                if ($lockedPeriod->status !== 'ready') {
                    $snapshot = $this->reviewSnapshot->captureForPeriod($lockedPeriod, $calendar);
                    $blockers = $this->readinessChecker->blockers($lockedPeriod, $calendar, $snapshot);
                    if ($blockers->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            'pay_period' => 'El período todavía tiene revisiones obligatorias pendientes.',
                        ]);
                    }

                    $lockedPeriod->update(['status' => 'ready']);
                }

                $run = PayrollRun::withoutCompanyScope()->create([
                    'request_key' => $requestKey,
                    'company_id' => $lockedPeriod->company_id,
                    'pay_period_id' => $lockedPeriod->id,
                    'requested_by' => $lockedActor->id,
                    'status' => PayrollRun::QUEUED,
                    'lease_expires_at' => now()->addMinutes(5),
                ]);
                $created = true;

                return $run;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $created = false;
            $run = PayrollRun::withoutCompanyScope()->where('request_key', $requestKey)->first()
                ?? PayrollRun::withoutCompanyScope()
                    ->where('pay_period_id', $period->id)
                    ->where('active_key', true)
                    ->first()
                ?? throw $exception;
        }

        if ($created) {
            DB::afterCommit(fn () => ProcessPayrollRun::dispatch($run->id));
        }

        return $run;
    }

    private function authorize(User $actor, PayPeriod $period): void
    {
        if (! $actor->is_active
            || ! $actor->can('payroll.process')
            || (! $actor->hasRole('super_admin') && $actor->company_id !== $period->company_id)) {
            throw new AuthorizationException('Not authorized to process this pay period.');
        }
    }
}
