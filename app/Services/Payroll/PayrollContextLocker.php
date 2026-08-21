<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\HolidayCalendar;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class PayrollContextLocker
{
    public const STAGE_PROFILES = 'profiles';

    public const STAGE_PUBLICATIONS = 'publications';

    public const STAGE_EMPLOYEES = 'employees';

    public const STAGE_ASSIGNMENTS = 'assignments';

    public const STAGE_RAW_MARKS = 'raw_marks';

    /** @var array<string, array{stage: ?string, models: array<class-string<Model>, array<int, Model>>}> */
    private static array $activeLeases = [];

    public function __construct(private HolidayCalendar $holidayCalendar) {}

    public function within(int $companyId, Closure $resolveTargets, Closure $work): mixed
    {
        return $this->run(
            $companyId,
            $resolveTargets,
            null,
            null,
            null,
            null,
            fn (LockedPayrollContext $context): mixed => $work($context),
        );
    }

    public function withinEmployeeCreation(
        int $companyId,
        Closure $resolveTargets,
        Closure $createEmployee,
        Closure $createAssignment,
        Closure $work,
    ): mixed {
        return $this->run(
            $companyId,
            $resolveTargets,
            null,
            null,
            fn (LockedPayrollContext $context): mixed => $createEmployee($context),
            fn (LockedPayrollContext $context, ?Employee $employee): mixed => $createAssignment($context, $employee),
            fn (LockedPayrollContext $context, ?Employee $employee, mixed $_profileResult, mixed $_publicationResult, mixed $assignmentResult): mixed => $work(
                $context,
                $employee,
                $assignmentResult,
            ),
        );
    }

    public function withinProfilePublication(
        int $companyId,
        Closure $resolveTargets,
        Closure $profileWork,
        Closure $publicationWork,
        Closure $assignmentWork,
    ): mixed {
        return $this->run(
            $companyId,
            $resolveTargets,
            fn (LockedPayrollContext $context): mixed => $profileWork($context),
            fn (LockedPayrollContext $context, mixed $profileResult): mixed => $publicationWork($context, $profileResult),
            null,
            fn (LockedPayrollContext $context, ?Employee $_employee, mixed $_profileResult, mixed $publicationResult): mixed => $assignmentWork($context, $publicationResult),
            fn (LockedPayrollContext $_context, ?Employee $_employee, mixed $_profileResult, mixed $_publicationResult, mixed $assignmentResult): mixed => $assignmentResult,
        );
    }

    public function withinAssignmentStage(
        int $companyId,
        Closure $resolveTargets,
        Closure $assignmentWork,
    ): mixed {
        return $this->run(
            $companyId,
            $resolveTargets,
            null,
            null,
            null,
            fn (LockedPayrollContext $context): mixed => $assignmentWork($context),
            fn (LockedPayrollContext $_context, ?Employee $_employee, mixed $_profileResult, mixed $_publicationResult, mixed $assignmentResult): mixed => $assignmentResult,
        );
    }

    public static function assertActive(string $lease): void
    {
        if (! isset(self::$activeLeases[$lease])) {
            throw new LogicException('Locked payroll context is not owned by an active PayrollContextLocker transaction.');
        }
    }

    public static function assertOwns(string $lease, Model $model): void
    {
        self::assertActive($lease);

        if ((self::$activeLeases[$lease]['models'][$model::class][(int) $model->getKey()] ?? null) !== $model) {
            throw new LogicException('Model is not owned by the active locked payroll context.');
        }
    }

    public static function assertStage(string $lease, string $stage): void
    {
        self::assertActive($lease);

        if (self::$activeLeases[$lease]['stage'] !== $stage) {
            throw new LogicException("Locked payroll context does not own the {$stage} mutation stage.");
        }
    }

    /** @param  array<string, mixed>  $attributes */
    public static function createOwnedProfile(string $lease, Company $company, array $attributes): WorkScheduleProfile
    {
        self::assertStage($lease, self::STAGE_PROFILES);
        self::assertOwns($lease, $company);

        if ((int) ($attributes['company_id'] ?? 0) !== $company->id) {
            throw new LogicException('Owned payroll profile must belong to the locked company.');
        }

        $profile = WorkScheduleProfile::withoutCompanyScope()->create($attributes);
        if (! $profile instanceof WorkScheduleProfile) {
            throw new LogicException('Owned payroll profile creation returned an unexpected model.');
        }
        self::registerOwned($lease, collect([$profile]));

        return $profile;
    }

    private function run(
        int $companyId,
        Closure $resolveTargets,
        ?Closure $profileWork,
        ?Closure $publicationWork,
        ?Closure $employeeWork,
        ?Closure $assignmentWork,
        Closure $rawMarkWork,
    ): mixed {
        return DB::transaction(function () use ($companyId, $resolveTargets, $profileWork, $publicationWork, $employeeWork, $assignmentWork, $rawMarkWork): mixed {
            $lease = bin2hex(random_bytes(16));
            self::$activeLeases[$lease] = ['stage' => null, 'models' => []];

            try {
                $company = Company::query()->whereKey($companyId)->orderBy('id')->lockForUpdate()->firstOrFail();
                $targets = $resolveTargets($company);

                if (! $targets instanceof PayrollContextTargets) {
                    throw new LogicException('Payroll context targets must use PayrollContextTargets.');
                }

                $payPeriods = $this->lockTargets(
                    PayPeriod::withoutCompanyScope()->withTrashed(),
                    $targets->payPeriodIds,
                    $companyId,
                    'pay period',
                );
                $profiles = $this->lockTargets(
                    WorkScheduleProfile::withoutCompanyScope(),
                    $targets->profileIds,
                    $companyId,
                    'work schedule profile',
                );
                self::registerOwned($lease, collect([$company])
                    ->concat($payPeriods)->concat($profiles));
                self::setStage($lease, self::STAGE_PROFILES);
                $profileResult = $profileWork?->__invoke(new LockedPayrollContext(
                    $company, $payPeriods, $profiles, collect(), collect(),
                    collect(), collect(), null, self::STAGE_PROFILES, $lease,
                ));
                self::rejectEscapedContext($profileResult);
                $publications = $this->lockTargets(
                    WorkScheduleProfilePublication::withoutCompanyScope(),
                    $targets->publicationIds,
                    $companyId,
                    'work schedule profile publication',
                );
                self::registerOwned($lease, $publications);
                self::setStage($lease, self::STAGE_PUBLICATIONS);
                $publicationResult = $publicationWork?->__invoke(new LockedPayrollContext(
                    $company, $payPeriods, $profiles, $publications, collect(),
                    collect(), collect(), null, self::STAGE_PUBLICATIONS, $lease,
                ), $profileResult);
                self::rejectEscapedContext($publicationResult);
                $employees = $this->lockTargets(
                    Employee::withoutCompanyScope()->withTrashed(),
                    $targets->employeeIds,
                    $companyId,
                    'employee',
                );
                self::registerOwned($lease, $employees);
                self::setStage($lease, self::STAGE_EMPLOYEES);
                $employee = null;
                if ($employeeWork !== null) {
                    $creationContext = new LockedPayrollContext(
                        $company, $payPeriods, $profiles, $publications, $employees,
                        collect(), collect(), null, self::STAGE_EMPLOYEES, $lease,
                    );
                    $attributes = $employeeWork($creationContext);

                    if (! is_array($attributes) || (int) ($attributes['company_id'] ?? 0) !== $companyId) {
                        throw new LogicException('The employee-creation stage must return attributes for the locked company.');
                    }

                    $employee = Employee::withoutCompanyScope()->create($attributes);
                    $employees = $employees->put($employee->id, $employee);
                    self::registerOwned($lease, collect([$employee]));
                }
                $assignments = $this->lockTargets(
                    EmployeeScheduleAssignment::withoutCompanyScope(),
                    $targets->assignmentIds,
                    $companyId,
                    'employee schedule assignment',
                );
                self::registerOwned($lease, $assignments);
                self::setStage($lease, self::STAGE_ASSIGNMENTS);
                $assignmentResult = $assignmentWork?->__invoke(new LockedPayrollContext(
                    $company, $payPeriods, $profiles, $publications, $employees,
                    $assignments, collect(), null, self::STAGE_ASSIGNMENTS, $lease,
                ), $employee, $profileResult, $publicationResult);
                self::rejectEscapedContext($assignmentResult);
                $rawMarks = $this->lockTargets(
                    RawMark::withoutCompanyScope(),
                    $targets->rawMarkIds,
                    $companyId,
                    'raw mark',
                );
                self::registerOwned($lease, $rawMarks);
                self::setStage($lease, self::STAGE_RAW_MARKS);
                $context = new LockedPayrollContext(
                    $company,
                    $payPeriods,
                    $profiles,
                    $publications,
                    $employees,
                    $assignments,
                    $rawMarks,
                    null,
                    self::STAGE_RAW_MARKS,
                    $lease,
                );
                if ($targets->holidayStart !== null) {
                    $context = $context->withHolidayCalendar($this->holidayCalendar->captureLocked(
                        $context,
                        $targets->holidayStart,
                        $targets->holidayEnd,
                    ));
                }
                $result = $rawMarkWork(
                    $context,
                    $employee,
                    $profileResult,
                    $publicationResult,
                    $assignmentResult,
                );

                self::rejectEscapedContext($result);

                return $result;
            } finally {
                unset(self::$activeLeases[$lease]);
            }
        });
    }

    /** @param  iterable<int, Model>  $models */
    private static function registerOwned(string $lease, iterable $models): void
    {
        foreach ($models as $model) {
            self::$activeLeases[$lease]['models'][$model::class][(int) $model->getKey()] = $model;
        }
    }

    private static function setStage(string $lease, string $stage): void
    {
        self::assertActive($lease);
        self::$activeLeases[$lease]['stage'] = $stage;
    }

    private static function rejectEscapedContext(mixed $result): void
    {
        if ($result instanceof LockedPayrollContext) {
            throw new LogicException('Locked payroll context cannot escape its transaction.');
        }
    }

    /**
     * @param  Builder<PayPeriod|WorkScheduleProfile|WorkScheduleProfilePublication|Employee|EmployeeScheduleAssignment|RawMark>  $query
     * @param  list<int>  $ids
     * @return Collection<int, PayPeriod|WorkScheduleProfile|WorkScheduleProfilePublication|Employee|EmployeeScheduleAssignment|RawMark>
     */
    private function lockTargets(Builder $query, array $ids, int $companyId, string $type): Collection
    {
        $ids = collect($ids)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $models = $query->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($models->count() !== $ids->count()
            || $models->contains(fn (mixed $model): bool => $model->company_id !== $companyId)) {
            throw ValidationException::withMessages([
                'payroll_context' => "A requested {$type} is missing or belongs to another company.",
            ]);
        }

        return $models;
    }
}
