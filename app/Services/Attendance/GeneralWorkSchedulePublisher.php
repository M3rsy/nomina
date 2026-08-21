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
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

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
        $publicationPlan = null;
        $schedules = $this->schedules();
        $definitionHash = hash('sha256', json_encode($schedules, JSON_THROW_ON_ERROR));

        if ($reason === '') {
            throw ValidationException::withMessages(['activationReason' => 'Ingresá el motivo de la activación.']);
        }

        try {
            return $this->contextLocker->withinProfilePublication(
                $company->id,
                function () use ($company, $requestedAt, $definitionHash, $reason, &$effectiveFrom, &$publicationPlan): PayrollContextTargets {
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
                    $publications = WorkScheduleProfilePublication::withoutCompanyScope()
                        ->where('company_id', $company->id)
                        ->where('profile_key', 'general')
                        ->orderBy('id')
                        ->get();
                    $publicationPlan = $this->publicationPlan(
                        $publications,
                        $company->id,
                        $effectiveFrom,
                        $definitionHash,
                        $reason,
                    );

                    return new PayrollContextTargets(
                        payPeriodIds: PayPeriod::withoutCompanyScope()->withTrashed()
                            ->where('company_id', $company->id)
                            ->whereDate('end_date', '>=', $effectiveFrom->subDay()->toDateString())
                            ->pluck('id')->all(),
                        profileIds: WorkScheduleProfile::withoutCompanyScope()->where('company_id', $company->id)
                            ->where('profile_key', 'general')->pluck('id')->all(),
                        publicationIds: $publications->pluck('id')->all(),
                        employeeIds: $employeeIds,
                        assignmentIds: EmployeeScheduleAssignment::withoutCompanyScope()
                            ->whereIn('employee_id', $employeeIds)->pluck('id')->all(),
                    );
                },
                function (LockedPayrollContext $context) use (&$publicationPlan, $schedules, $actor, $reason): array {
                    if (! is_array($publicationPlan)) {
                        throw new LogicException('General schedule publication preflight was not resolved.');
                    }

                    return $this->writeProfileLocked($context, $publicationPlan, $schedules, $actor, $reason);
                },
                function (LockedPayrollContext $context, array $profileState) use (&$effectiveFrom, &$publicationPlan, $definitionHash, $reason, $actor): array {
                    if (! is_array($publicationPlan)) {
                        throw new LogicException('General schedule publication preflight was not resolved.');
                    }

                    return $this->writePublicationLocked(
                        $context,
                        $profileState,
                        $publicationPlan,
                        $effectiveFrom,
                        $definitionHash,
                        $reason,
                        $actor,
                    );
                },
                function (LockedPayrollContext $context, array $publication) use (&$effectiveFrom, $actor, $reason): WorkScheduleProfilePublication {
                    return $this->assignEmployeesLocked(
                        $context,
                        $publication,
                        $effectiveFrom,
                        $actor,
                        $reason,
                    );
                },
            );
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23503', '23505', '23P01'], true)) {
                throw ValidationException::withMessages(['activationReason' => 'La publicación entra en conflicto con el historial vigente.']);
            }
            throw $exception;
        }
    }

    /**
     * @param  array{mode: string, publication_id: ?int, previous_id: ?int, definition_hash: string, request_key: string, payload_hash: string}  $plan
     * @param  list<array<string, mixed>>  $schedules
     * @return array{plan: array<string, mixed>, profile: ?WorkScheduleProfile}
     */
    private function writeProfileLocked(
        LockedPayrollContext $context,
        array $plan,
        array $schedules,
        User $actor,
        string $reason,
    ): array {
        $context->assertStage(PayrollContextLocker::STAGE_PROFILES);

        if ($plan['mode'] === 'replay') {
            return ['plan' => $plan, 'profile' => null];
        }

        $version = ((int) $context->profiles->max('version')) + 1;
        $profile = WorkScheduleProfile::withoutEvents(fn () => $context->createOwnedProfile([
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

        $context->profiles->where('is_active', true)->each->update(['is_active' => false]);

        return ['plan' => $plan, 'profile' => $profile];
    }

    /**
     * @param  array{plan: array<string, mixed>, profile: ?WorkScheduleProfile}  $profileState
     * @param  array{mode: string, publication_id: ?int, previous_id: ?int, definition_hash: string, request_key: string, payload_hash: string}  $preflightPlan
     * @return array{publication: WorkScheduleProfilePublication, profile: ?WorkScheduleProfile}
     */
    private function writePublicationLocked(
        LockedPayrollContext $context,
        array $profileState,
        array $preflightPlan,
        CarbonImmutable $effectiveFrom,
        string $definitionHash,
        string $reason,
        User $actor,
    ): array {
        $context->assertStage(PayrollContextLocker::STAGE_PUBLICATIONS);
        $lockedPlan = $this->publicationPlan(
            $context->publications,
            $context->company->id,
            $effectiveFrom,
            $definitionHash,
            $reason,
        );

        if ($lockedPlan !== $preflightPlan || $profileState['plan'] !== $preflightPlan) {
            $this->rejectPublicationConflict();
        }

        if ($lockedPlan['mode'] === 'replay') {
            if ($profileState['profile'] !== null || $lockedPlan['publication_id'] === null) {
                $this->rejectPublicationConflict();
            }

            return [
                'publication' => $context->publication($lockedPlan['publication_id']),
                'profile' => null,
            ];
        }

        $profile = $profileState['profile'];
        if (! $profile instanceof WorkScheduleProfile || $lockedPlan['previous_id'] === null) {
            $this->rejectPublicationConflict();
        }
        $context->assertOwns($profile);
        $context->publication($lockedPlan['previous_id'])
            ->update(['effective_to' => $effectiveFrom->toDateString()]);
        $publication = WorkScheduleProfilePublication::withoutCompanyScope()->create([
            'company_id' => $context->company->id,
            'profile_key' => 'general',
            'profile_id' => $profile->id,
            'payroll_policy_key' => WorkScheduleProfilePublication::DURATION_FIRST_V2,
            'effective_from' => $effectiveFrom->toDateString(),
            'effective_to' => null,
            'definition_hash' => $lockedPlan['definition_hash'],
            'request_key' => $lockedPlan['request_key'],
            'payload_hash' => $lockedPlan['payload_hash'],
            'reason' => $reason,
            'published_by' => $actor->id,
        ]);
        if (! $publication instanceof WorkScheduleProfilePublication) {
            throw new LogicException('General schedule publication creation returned an unexpected model.');
        }

        return ['publication' => $publication, 'profile' => $profile];
    }

    /**
     * @param  Collection<int, WorkScheduleProfilePublication>  $publications
     * @return array{mode: string, publication_id: ?int, previous_id: ?int, definition_hash: string, request_key: string, payload_hash: string}
     */
    private function publicationPlan(
        Collection $publications,
        int $companyId,
        CarbonImmutable $effectiveFrom,
        string $definitionHash,
        string $reason,
    ): array {
        $requestKey = hash('sha256', "general-v2|{$companyId}|{$effectiveFrom->toDateString()}");
        $payloadHash = hash('sha256', "{$requestKey}|{$definitionHash}|{$reason}");
        $existing = $publications->firstWhere('request_key', $requestKey);
        if ($existing !== null) {
            if ($existing->payroll_policy_key === WorkScheduleProfilePublication::DURATION_FIRST_V2
                && $existing->effective_from->isSameDay($effectiveFrom)
                && hash_equals($existing->payload_hash, $payloadHash)) {
                return [
                    'mode' => 'replay',
                    'publication_id' => $existing->id,
                    'previous_id' => null,
                    'definition_hash' => $definitionHash,
                    'request_key' => $requestKey,
                    'payload_hash' => $payloadHash,
                ];
            }

            $this->rejectPublicationConflict();
        }

        $previous = $publications->filter(fn (WorkScheduleProfilePublication $publication): bool => $publication->effective_from->lt($effectiveFrom)
            && ($publication->effective_to === null || $publication->effective_to->gte($effectiveFrom))
        );
        if ($previous->count() !== 1) {
            throw ValidationException::withMessages([
                'activationReason' => 'La jornada general vigente no se puede resolver de forma única.',
            ]);
        }

        return [
            'mode' => 'publish',
            'publication_id' => null,
            'previous_id' => $previous->sole()->id,
            'definition_hash' => $definitionHash,
            'request_key' => $requestKey,
            'payload_hash' => $payloadHash,
        ];
    }

    private function rejectPublicationConflict(): never
    {
        throw ValidationException::withMessages([
            'activationReason' => 'La activación solicitada entra en conflicto con una publicación existente.',
        ]);
    }

    /**
     * @param  array{publication: WorkScheduleProfilePublication, profile: ?WorkScheduleProfile}  $publication
     */
    private function assignEmployeesLocked(
        LockedPayrollContext $context,
        array $publication,
        CarbonImmutable $effectiveFrom,
        User $actor,
        string $reason,
    ): WorkScheduleProfilePublication {
        if ($publication['profile'] === null) {
            return $publication['publication'];
        }

        foreach ($context->employees as $employee) {
            $this->assigner->assignLocked(
                $context,
                $employee,
                $publication['profile'],
                $effectiveFrom,
                $reason,
                $actor,
            );
        }

        return $publication['publication'];
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
