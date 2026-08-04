<?php

namespace App\Services\Attendance;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Payroll\LockedPayrollContext;
use App\Services\Payroll\PayrollContextLocker;
use App\Services\Payroll\PayrollContextTargets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class GeneralWorkSchedulePublisher
{
    public function __construct(
        private PayrollContextLocker $contextLocker,
        private EmployeeScheduleAssigner $assigner,
    ) {}

    public function activate(
        Company $company,
        User $actor,
        string $reason,
        CarbonInterface|string|null $requestedAt = null,
    ): WorkScheduleProfilePublication {
        $reason = trim($reason);
        $requestedAt = CarbonImmutable::parse($requestedAt ?? now());
        $effectiveFrom = null;

        if ($reason === '') {
            throw ValidationException::withMessages(['activationReason' => 'Ingresá el motivo de la activación.']);
        }

        try {
            return $this->contextLocker->within(
                $company->id,
                function () use ($company, $requestedAt, &$effectiveFrom): PayrollContextTargets {
                    $period = PayPeriod::withoutCompanyScope()
                        ->where('company_id', $company->id)
                        ->whereDate('start_date', '>', $requestedAt->toDateString())
                        ->orderBy('start_date')->orderBy('id')->first();
                    if ($period === null) {
                        throw ValidationException::withMessages([
                            'activationReason' => 'No existe un período de nómina posterior disponible para la activación.',
                        ]);
                    }
                    $effectiveFrom = CarbonImmutable::instance($period->start_date);
                    $employeeIds = Employee::withoutCompanyScope()->where('company_id', $company->id)
                        ->where('is_active', true)->orderBy('id')->pluck('id')->all();

                    return new PayrollContextTargets(
                        payPeriodIds: PayPeriod::withoutCompanyScope()->withTrashed()
                            ->where('company_id', $company->id)
                            ->whereDate('end_date', '>=', $effectiveFrom->subDay()->toDateString())
                            ->pluck('id')->all(),
                        profileIds: WorkScheduleProfile::withoutCompanyScope()->where('company_id', $company->id)
                            ->where('profile_key', 'general')->pluck('id')->all(),
                        publicationIds: WorkScheduleProfilePublication::withoutCompanyScope()->where('company_id', $company->id)
                            ->where('profile_key', 'general')->pluck('id')->all(),
                        employeeIds: $employeeIds,
                        assignmentIds: EmployeeScheduleAssignment::withoutCompanyScope()
                            ->whereIn('employee_id', $employeeIds)->pluck('id')->all(),
                    );
                },
                function (LockedPayrollContext $context) use (&$effectiveFrom, $actor, $reason): WorkScheduleProfilePublication {
                    return $this->publishLocked($context, $effectiveFrom, $actor, $reason);
                },
            );
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23503', '23505', '23P01'], true)) {
                throw ValidationException::withMessages(['activationReason' => 'La publicación entra en conflicto con el historial vigente.']);
            }
            throw $exception;
        }
    }

    private function publishLocked(
        LockedPayrollContext $context,
        CarbonImmutable $effectiveFrom,
        User $actor,
        string $reason,
    ): WorkScheduleProfilePublication {
        $schedules = $this->schedules();
        $definitionHash = hash('sha256', json_encode($schedules, JSON_THROW_ON_ERROR));
        $requestKey = hash('sha256', "general-v2|{$context->company->id}|{$effectiveFrom->toDateString()}");
        $payloadHash = hash('sha256', "{$requestKey}|{$definitionHash}|{$reason}");
        $existing = $context->publications->firstWhere('request_key', $requestKey);
        if ($existing !== null) {
            if ($existing->payroll_policy_key === WorkScheduleProfilePublication::DURATION_FIRST_V2
                && $existing->effective_from->isSameDay($effectiveFrom)
                && hash_equals($existing->payload_hash, $payloadHash)) {
                return $existing;
            }

            throw ValidationException::withMessages(['activationReason' => 'La activación solicitada entra en conflicto con una publicación existente.']);
        }

        $previous = $context->publications->filter(fn (WorkScheduleProfilePublication $publication): bool => $publication->effective_from->lt($effectiveFrom)
            && ($publication->effective_to === null || $publication->effective_to->gte($effectiveFrom))
        );
        if ($previous->count() !== 1) {
            throw ValidationException::withMessages([
                'activationReason' => 'La jornada general vigente no se puede resolver de forma única.',
            ]);
        }

        $version = ((int) $context->profiles->max('version')) + 1;
        $profile = WorkScheduleProfile::withoutEvents(fn () => WorkScheduleProfile::withoutCompanyScope()->create([
            'company_id' => $context->company->id,
            'profile_key' => 'general',
            'name' => 'Jornada general',
            'version' => $version,
            'is_active' => true,
            'created_by' => $actor->id,
            'change_reason' => $reason,
        ]));
        foreach ($schedules as $schedule) {
            WorkSchedule::withoutCompanyScope()->create([
                ...$schedule,
                'company_id' => $context->company->id,
                'work_schedule_profile_id' => $profile->id,
            ]);
        }

        $previous->sole()->update(['effective_to' => $effectiveFrom->toDateString()]);
        $publication = WorkScheduleProfilePublication::withoutCompanyScope()->create([
            'company_id' => $context->company->id,
            'profile_key' => 'general',
            'profile_id' => $profile->id,
            'payroll_policy_key' => WorkScheduleProfilePublication::DURATION_FIRST_V2,
            'effective_from' => $effectiveFrom->toDateString(),
            'effective_to' => null,
            'definition_hash' => $definitionHash,
            'request_key' => $requestKey,
            'payload_hash' => $payloadHash,
            'reason' => $reason,
            'published_by' => $actor->id,
        ]);

        $context->profiles->where('is_active', true)->each->update(['is_active' => false]);
        foreach ($context->employees as $employee) {
            $this->assigner->assignLocked($context, $employee, $profile, $effectiveFrom, $reason, $actor);
        }

        return $publication;
    }

    private function schedules(): array
    {
        return collect(range(0, 6))->map(fn (int $day): array => [
            'day_of_week' => $day,
            'is_working_day' => $day !== 0,
            'base_ordinary_hours' => $day === 0 ? 0 : 8,
            'start_time' => $day === 0 ? null : '06:00',
            'end_time' => $day === 0 ? null : '14:00',
            'banding_json' => null,
            'notes' => null,
        ])->all();
    }
}
